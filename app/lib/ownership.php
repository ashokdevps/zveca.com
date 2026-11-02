<?php
/**
 * Vendor Performance Score (industry-wise) and Candidate Ownership Lock.
 *
 * Vendor Performance Score — an internal 0–100 rating of a staffing partner per hiring industry (not a credit score).
 *   Bands: 85–100 / 65–84 / 0–64, labels from the "vendor_score_labels" setting (default Excellent | Good | Better).
 *   Everyone with Sub-vendors or Recruitment access sees scores; only "manage_vendor_scores" adds or edits them.
 *
 * Candidate Ownership Lock — when a vendor (or its linked sub-vendor) submits a candidate, the candidate is protected for
 * that client company only (client_id 0 = our own company) for "lock_days". Matching uses the normalised e-mail AND
 * mobile number; when one matches and the other contradicts, the submission waits for an authorised review instead of
 * being merged or rejected automatically. Claims for one company are serialised with a database lock, so two vendors
 * submitting the same person at the same moment cannot both win. Expiry never overrides an active client submission:
 * shortlisted / interviewing / offered candidates are "Held", hired ones "Converted" (kept), others are released.
 * Every step is written to hrm_lock_events.
 */

/* ═════════════════════════ Vendor Performance Score ═════════════════════════ */

function score_labels(): array
{
    $l = array_map('trim', explode('|', (string) setting('vendor_score_labels', 'Excellent|Good|Better')));
    return [($l[0] ?? '') ?: 'Excellent', ($l[1] ?? '') ?: 'Good', ($l[2] ?? '') ?: 'Better'];
}

/** Category of a score: ['key', 'label', 'tone', 'range']. */
function score_band(?int $s): ?array
{
    if ($s === null) return null;
    [$hi, $mid, $lo] = score_labels();
    if ($s >= 85) return ['key' => 'excellent', 'label' => $hi, 'tone' => 'success', 'range' => '85–100'];
    if ($s >= 65) return ['key' => 'good', 'label' => $mid, 'tone' => 'warning', 'range' => '65–84'];
    return ['key' => 'low', 'label' => $lo, 'tone' => 'danger', 'range' => '0–64'];
}

/** SQL condition for a band key on a score column. */
function score_band_sql(string $key, string $col = 'score'): string
{
    return match ($key) { 'excellent' => "$col >= 85", 'good' => "$col BETWEEN 65 AND 84", 'low' => "$col < 65", default => '1=1' };
}

/** Coloured score chip: "92/100 · Excellent". $size: '', 'lg' or 'sm'. */
function score_badge(?int $s, string $size = '', ?string $prefix = null): string
{
    $b = score_band($s);
    if (!$b) return '<span class="vscore vscore-none' . ($size ? " vscore-$size" : '') . '" title="Not scored for this industry">' . ($prefix ? e($prefix) . ' ' : '') . 'Not scored</span>';
    return '<span class="vscore vscore-' . $b['key'] . ($size ? " vscore-$size" : '') . '" title="Vendor Performance Score: ' . $s . '/100 (' . e($b['label']) . ', ' . $b['range'] . ')">'
        . ($prefix ? '<em>' . e($prefix) . '</em>' : '') . '<b>' . $s . '</b><small>/100</small><span>' . e($b['label']) . '</span></span>';
}

function can_view_vendor_scores(): bool
{
    return plan_allows('manage_vendors') && can_any('manage_vendors', 'manage_recruitment', 'manage_vendor_scores');
}

function can_edit_vendor_scores(): bool
{
    return plan_allows('manage_vendors') && can('manage_vendor_scores');
}

/** Active industries id => name. */
function industry_options(bool $activeOnly = true): array
{
    return array_column(all('SELECT id, name FROM hrm_industries' . ($activeOnly ? " WHERE status = 'Active'" : '') . ' ORDER BY name'), 'name', 'id');
}

/** Every score of a vendor, best first: [industry_id, industry, score, note, updated_at, updated_by_name]. */
function vendor_scores(int $vendorId): array
{
    return all('SELECT s.*, i.name industry, i.status industry_status, l.username updated_by_name FROM hrm_vendor_scores s JOIN hrm_industries i ON i.id = s.industry_id
                LEFT JOIN it_login l ON l.id = s.updated_by WHERE s.vendor_id = ? ORDER BY s.score DESC, i.name', [$vendorId]);
}

function vendor_score_for(int $vendorId, int $industryId): ?int
{
    $v = val('SELECT score FROM hrm_vendor_scores WHERE vendor_id = ? AND industry_id = ?', [$vendorId, $industryId]);
    return $v === null || $v === false ? null : (int) $v;
}

/** Saves (or clears with $score = null) a vendor's score for one industry, with history. */
function vendor_score_set(int $vendorId, int $industryId, ?int $score, string $note = ''): void
{
    $old = vendor_score_for($vendorId, $industryId);
    $note = mb_substr(trim($note), 0, 500);
    if ($score === null) {
        q('DELETE FROM hrm_vendor_scores WHERE vendor_id = ? AND industry_id = ?', [$vendorId, $industryId]);
    } else {
        $score = max(0, min(100, $score));
        q('INSERT INTO hrm_vendor_scores (vendor_id, industry_id, score, note, updated_by) VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE score = VALUES(score), note = VALUES(note), updated_by = VALUES(updated_by)', [$vendorId, $industryId, $score, $note ?: null, user()['id'] ?? null]);
    }
    insert('hrm_vendor_score_history', ['vendor_id' => $vendorId, 'industry_id' => $industryId, 'old_score' => $old, 'new_score' => $score, 'note' => $note ?: null, 'user_id' => user()['id'] ?? null]);
    $ind = (string) val('SELECT name FROM hrm_industries WHERE id = ?', [$industryId]);
    log_activity($score === null ? 'score_removed' : 'score_updated', 'vendor', $vendorId, $ind . ': ' . ($old ?? '—') . ' → ' . ($score ?? '—'));
}

/* ═════════════════════════ Identity normalisation ═════════════════════════ */

/** Same e-mail rule as the central candidate database: trimmed, lower-case, validated (no provider-specific rewriting). */
function norm_email(?string $e): ?string
{
    return cand_email_norm($e);
}

/** Mobile number as its last 10 digits (drops +91 / 0 / spaces / dashes), or null when it is not a full number. */
function norm_phone(?string $p): ?string
{
    $k = phone_key($p);
    return strlen($k) === 10 ? $k : null;
}

/* ═════════════════════════ Settings & helpers ═════════════════════════ */

function lock_on(): bool
{
    return setting('lock_enabled', '1') === '1' && plan_allows('manage_vendors');
}

function lock_days(): int
{
    return max(1, min(365, (int) setting('lock_days', 30)));
}

/** Pipeline stages during which an expired lock is held (the client is actively considering the candidate). */
function lock_hold_stages(): array
{
    $s = array_values(array_intersect(array_map('trim', explode(',', (string) setting('lock_hold_stages', 'Shortlisted,HR Screening,Interview,Client Interview,Selected,Offered'))), CANDIDATE_STAGES));
    return $s ?: ['Shortlisted', 'HR Screening', 'Interview', 'Client Interview', 'Selected', 'Offered'];
}

const LOCK_LIVE = ['Active', 'Held', 'Converted']; // statuses that protect a candidate
const LOCK_STATUS_TONES = ['Active' => 'success', 'Held' => 'warning', 'Converted' => 'info', 'Expired' => 'neutral', 'Released' => 'neutral'];

/** [owning vendor id, sub-vendor id|null]: a sub-vendor's claims belong to its parent partner. */
function vendor_owner(int $vendorId): array
{
    $parent = (int) val("SELECT p.id FROM hrm_vendors v JOIN hrm_vendors p ON p.id = v.parent_vendor_id WHERE v.id = ? AND p.id <> v.id", [$vendorId]);
    return $parent ? [$parent, $vendorId] : [$vendorId, null];
}

/** A partner and all of its sub-vendors (ids). */
function vendor_group(int $vendorId): array
{
    [$root] = vendor_owner($vendorId);
    return array_map('intval', array_merge([$root], array_column(all('SELECT id FROM hrm_vendors WHERE parent_vendor_id = ? AND id <> ?', [$root, $root]), 'id')));
}

function client_label(int $clientId): string
{
    if ($clientId === 0) return setting('company_name', 'Zveca') . ' (direct)';
    return (string) (val('SELECT name FROM hrm_clients WHERE id = ?', [$clientId]) ?: 'Client #' . $clientId);
}

/** "12d 4h left", "3h 20m left", "expired". */
function lock_remaining(array $lock): string
{
    if ($lock['status'] === 'Converted') return 'Kept (hired)';
    if (!in_array($lock['status'], ['Active', 'Held'], true)) return '—';
    $s = strtotime($lock['expires_at']) - time();
    if ($s <= 0) return $lock['status'] === 'Held' ? 'Held: in client process' : 'Expiring now';
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    return ($d ? "{$d}d {$h}h" : ($h ? "{$h}h {$m}m" : "{$m}m")) . ' left';
}

/** Live countdown element (hrms.js updates [data-countdown] every 30 s). */
function lock_timer(array $lock): string
{
    $status = $lock['status'];
    if ($status === 'Active' || ($status === 'Held' && strtotime($lock['expires_at']) > time())) {
        $total = max(1, strtotime($lock['expires_at']) - strtotime($lock['locked_at']));
        $pct = max(0, min(100, (int) round((strtotime($lock['expires_at']) - time()) / $total * 100)));
        return '<span class="lock-timer' . ($pct < 15 ? ' low' : '') . '" data-countdown="' . e(date('c', strtotime($lock['expires_at']))) . '" title="Expires ' . e(fdate($lock['expires_at'], 'd M Y, h:i A')) . '">'
            . '<i class="ri-timer-line"></i><span data-countdown-text>' . e(lock_remaining($lock)) . '</span><span class="lock-timer-bar"><i style="width:' . $pct . '%"></i></span></span>';
    }
    return '<span class="small text-muted">' . e(lock_remaining($lock)) . '</span>';
}

function lock_status_pill(string $status): string
{
    return '<span class="pill pill-' . (LOCK_STATUS_TONES[$status] ?? 'neutral') . '" title="' . e($status) . '">' . e($status === 'Converted' ? 'Hired · kept' : $status) . '</span>';
}

/* ═════════════════════════ Audit ═════════════════════════ */

const LOCK_EVENT_LABELS = [
    'created' => 'Ownership created', 'claim_own' => 'Submission under own lock', 'claim_blocked' => 'Competing claim blocked', 'match' => 'Duplicate check',
    'review_opened' => 'Sent for review', 'review_approved' => 'Review: different person (approved)', 'review_rejected' => 'Review: same person (claim rejected)',
    'extended' => 'Lock extended', 'released' => 'Released manually', 'expired' => 'Expired and released', 'held' => 'Expiry held (client process)',
    'converted' => 'Kept: candidate hired', 'reminder' => 'Expiry reminder sent', 'assigned' => 'Owner assigned by HR', 'profile_blocked' => 'Protected profile access refused',
];

function lock_event(?int $lockId, string $event, array $ctx = [], string $detail = ''): void
{
    try {
        insert('hrm_lock_events', ['lock_id' => $lockId, 'candidate_id' => $ctx['candidate_id'] ?? null, 'client_id' => $ctx['client_id'] ?? null, 'vendor_id' => $ctx['vendor_id'] ?? null,
            'event' => $event, 'detail' => $detail !== '' ? mb_substr($detail, 0, 500) : null, 'user_id' => user()['id'] ?? null, 'ip' => PHP_SAPI === 'cli' ? null : client_ip()]);
    } catch (Throwable $e) {
        error_log('Lock audit: ' . $e->getMessage());
    }
}

/* ═════════════════════════ Matching & claiming ═════════════════════════ */

/**
 * Runs $fn while holding the claim lock for one client company, so concurrent submissions for that company are
 * checked and written one after the other. Throws RuntimeException when the lock can't be obtained in 10 s.
 */
function lock_guard(int $clientId, callable $fn)
{
    $name = 'hrms_own_' . (int) $clientId . '_' . substr(md5((string) config('db_name', '')), 0, 8);
    if ((int) val('SELECT GET_LOCK(?, 10)', [$name]) !== 1) throw new RuntimeException('The system is busy checking another submission for this company. Please try again in a moment.');
    try {
        return $fn();
    } finally {
        val('SELECT RELEASE_LOCK(?)', [$name]);
    }
}

/**
 * Protecting locks for one company that share the e-mail or mobile.
 *   ['status' => 'none'] | ['status' => 'match', 'lock' => row] | ['status' => 'conflict', 'locks' => rows, 'why' => text]
 * A match needs one identifier equal and the other equal or unknown; one equal and the other different is a conflict.
 */
function lock_match(int $clientId, ?string $email, ?string $phone): array
{
    if ($email === null && $phone === null) return ['status' => 'none'];
    $rows = all('SELECT * FROM hrm_candidate_locks WHERE client_id = ? AND status IN ' . sql_list(LOCK_LIVE) . ' AND ((email_norm IS NOT NULL AND email_norm = ?) OR (phone_norm IS NOT NULL AND phone_norm = ?)) ORDER BY id',
        [$clientId, $email ?? '', $phone ?? '']);
    $conflicts = [];
    foreach ($rows as $l) {
        $eEq = $email !== null && $l['email_norm'] === $email;
        $pEq = $phone !== null && $l['phone_norm'] === $phone;
        $eDiff = $email !== null && $l['email_norm'] !== null && $l['email_norm'] !== $email;
        $pDiff = $phone !== null && $l['phone_norm'] !== null && $l['phone_norm'] !== $phone;
        if (($eEq || $pEq) && !$eDiff && !$pDiff) return ['status' => 'match', 'lock' => $l];
        $conflicts[] = $l + ['_why' => $eEq ? 'same e-mail, different mobile number' : 'same mobile number, different e-mail'];
    }
    return $conflicts ? ['status' => 'conflict', 'locks' => $conflicts, 'why' => implode('; ', array_unique(array_column($conflicts, '_why')))] : ['status' => 'none'];
}

/** Creates an ownership lock. $o: candidate_id, email, phone (normalised), client_id, job_id, vendor_id (submitter), locked_at?, days?, event?, detail? */
function lock_create(array $o): int
{
    [$owner, $sub] = vendor_owner((int) $o['vendor_id']);
    $at = $o['locked_at'] ?? date('Y-m-d H:i:s');
    $days = (int) ($o['days'] ?? lock_days());
    $id = insert('hrm_candidate_locks', ['candidate_id' => $o['candidate_id'] ?? null, 'email_norm' => $o['email'], 'phone_norm' => $o['phone'], 'client_id' => (int) $o['client_id'],
        'job_id' => $o['job_id'] ?? null, 'vendor_id' => $owner, 'sub_vendor_id' => $sub, 'locked_at' => $at, 'expires_at' => date('Y-m-d H:i:s', strtotime($at) + $days * 86400),
        'status' => 'Active', 'created_by' => user()['id'] ?? null]);
    lock_event($id, $o['event'] ?? 'created', ['candidate_id' => $o['candidate_id'] ?? null, 'client_id' => $o['client_id'], 'vendor_id' => $owner],
        $o['detail'] ?? ('Protected for ' . client_label((int) $o['client_id']) . " for $days days" . ($sub ? ' (submitted by sub-vendor #' . $sub . ')' : '')));
    return $id;
}

/**
 * Decides a vendor's claim on a candidate for a job's company (call inside lock_guard()).
 *   ['result' => 'free'] → create the submission and a new lock
 *   ['result' => 'own', 'lock' => …] → the vendor's own group already holds it: submit, no new lock
 *   ['result' => 'blocked', 'lock' => …] → another partner holds it for this company
 *   ['result' => 'review', 'match' => …] → identifiers contradict an existing lock: hold for review
 */
function lock_claim(int $vendorId, int $clientId, ?string $email, ?string $phone): array
{
    $m = lock_match($clientId, $email, $phone);
    if ($m['status'] === 'none') return ['result' => 'free'];
    if ($m['status'] === 'conflict') return ['result' => 'review', 'match' => $m];
    $group = vendor_group($vendorId);
    return in_array((int) $m['lock']['vendor_id'], $group, true) ? ['result' => 'own', 'lock' => $m['lock']] : ['result' => 'blocked', 'lock' => $m['lock']];
}

/** Inserts a vendor submission into the pipeline (used by the vendor portal and by approved reviews). */
function vendor_submission_insert(int $vendorId, array $job, array $data, string $note, ?int $userId): int
{
    $agency = (string) val('SELECT name FROM hrm_vendors WHERE id = ?', [$vendorId]);
    $cols = array_intersect_key($data, SUBMISSION_FIELDS + ['resume' => 1]);
    // Central candidate service: linked to the person's master record when they already exist; partner attests consent.
    $id = cand_insert($cols + ['job_id' => (int) $job['id'], 'vendor_id' => $vendorId, 'submitted_by' => $userId, 'source' => 'Consultant', 'stage' => 'Applied',
        'notes' => $note !== '' ? 'Vendor note (' . $agency . '): ' . mb_substr($note, 0, 1000) : null], 'vendor submission', 'Partner attestation');
    log_activity('submitted', 'candidate', $id, $agency . ' → ' . $job['title']);
    return $id;
}

/* ═════════════════════════ Release / extend / assign ═════════════════════════ */

function lock_find(int $id): ?array
{
    return one('SELECT l.*, v.name vendor, sv.name sub_vendor, c.name candidate, c.email cand_email, c.phone cand_phone, c.stage cand_stage, j.title job
                FROM hrm_candidate_locks l LEFT JOIN hrm_vendors v ON v.id = l.vendor_id LEFT JOIN hrm_vendors sv ON sv.id = l.sub_vendor_id
                LEFT JOIN hrm_candidates c ON c.id = l.candidate_id LEFT JOIN hrm_jobs j ON j.id = l.job_id WHERE l.id = ?', [$id]);
}

function lock_release(int $id, string $reason, string $status = 'Released', string $event = 'released'): bool
{
    $l = lock_find($id);
    if (!$l || !in_array($l['status'], LOCK_LIVE, true)) return false;
    q('UPDATE hrm_candidate_locks SET status = ?, released_at = NOW(), released_by = ?, release_reason = ? WHERE id = ?', [$status, user()['id'] ?? null, mb_substr($reason, 0, 255), $id]);
    lock_event($id, $event, ['candidate_id' => $l['candidate_id'], 'client_id' => $l['client_id'], 'vendor_id' => $l['vendor_id']], $reason);
    lock_notify($l, 'Ownership released: ' . ($l['candidate'] ?? 'candidate') . ' for ' . client_label((int) $l['client_id']),
        "Your ownership of {$l['candidate']} for " . client_label((int) $l['client_id']) . " has ended ($status). Reason: $reason\n\nThe candidate can now be submitted for this company by any partner.");
    return true;
}

function lock_extend(int $id, int $days, string $reason): bool
{
    $l = lock_find($id);
    if (!$l || !in_array($l['status'], ['Active', 'Held'], true)) return false;
    $days = max(1, min(max(1, (int) setting('lock_max_extension_days', 30)), $days));
    $base = max(time(), strtotime($l['expires_at']));
    $new = date('Y-m-d H:i:s', $base + $days * 86400);
    q("UPDATE hrm_candidate_locks SET expires_at = ?, status = 'Active', extensions = extensions + 1 WHERE id = ?", [$new, $id]);
    lock_event($id, 'extended', ['candidate_id' => $l['candidate_id'], 'client_id' => $l['client_id'], 'vendor_id' => $l['vendor_id']], "+$days days to " . fdate($new, 'd M Y') . ". Reason: $reason");
    lock_notify($l, 'Ownership extended: ' . ($l['candidate'] ?? 'candidate'), "Your ownership of {$l['candidate']} for " . client_label((int) $l['client_id']) . " was extended by $days days, until " . fdate($new, 'd M Y, h:i A') . ".");
    return true;
}

/** E-mails the owning partner (agency e-mail and its active portal logins, plus the sub-vendor's). */
function lock_notify(array $l, string $subject, string $body): void
{
    $ids = array_filter([(int) $l['vendor_id'], (int) ($l['sub_vendor_id'] ?? 0)]);
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    $to = array_unique(array_filter(array_merge(array_column(all("SELECT email FROM hrm_vendors WHERE id IN ($in)"), 'email'),
        array_column(all("SELECT email FROM it_login WHERE vendor_id IN ($in) AND status = 'active'"), 'email'))));
    $company = setting('company_name', 'Zveca');
    foreach ($to as $addr) {
        try {
            send_mail($addr, $subject, "Hello,\n\n$body\n\nVendor portal: " . (PHP_SAPI === 'cli' ? '' : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . url('vendor', ['a' => 'locks']))) . "\n\n— $company Recruitment");
        } catch (Throwable $e) {
            error_log('Lock notification: ' . $e->getMessage());
        }
    }
}

/* ═════════════════════════ Timer: reminders, expiry, holds ═════════════════════════ */

/** Highest pipeline stage of the candidate under a lock (its own submission and any later ones by the same partner for that company). */
function lock_candidate_stage(array $l): ?string
{
    $group = vendor_group((int) $l['vendor_id']);
    $in = implode(',', array_map('intval', $group));
    $params = [(int) $l['client_id']];
    $cond = [];
    if ($l['email_norm']) { $cond[] = 'LOWER(c.email) = ?'; $params[] = $l['email_norm']; }
    if ($l['phone_norm']) { $cond[] = phone_key_sql('c.phone') . ' = ?'; $params[] = $l['phone_norm']; }
    $byContact = $cond ? ' OR (' . implode(' OR ', $cond) . ')' : '';
    $stages = array_column(all("SELECT c.stage FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE COALESCE(j.client_id, 0) = ? AND c.vendor_id IN ($in)
                                AND (c.id = " . (int) $l['candidate_id'] . "$byContact)", $params), 'stage');
    if (!$stages && $l['candidate_id']) $stages = [(string) val('SELECT stage FROM hrm_candidates WHERE id = ?', [$l['candidate_id']])];
    if (in_array('Hired', $stages, true)) return 'Hired';
    foreach (array_reverse(CANDIDATE_STAGES) as $s) if ($s !== 'Rejected' && in_array($s, $stages, true)) return $s;
    return $stages ? 'Rejected' : null;
}

/**
 * Reminders before expiry, automatic release, holds while the client is still considering the candidate, and
 * conversion when hired. Runs at most every 30 minutes from page loads, or from app/cron.php. Returns actions taken.
 */
function lock_tick(bool $force = false): int
{
    if (!$force) {
        if (time() - (int) setting('lock_tick_at', 0) < 1800) return 0;
        set_setting('lock_tick_at', time());
    }
    $n = 0;
    $days = max(0, (int) setting('lock_reminder_days', 3));
    // 1. Reminder once per expiry date (an extension gives a new reminder).
    if ($days) {
        foreach (all("SELECT l.*, c.name candidate FROM hrm_candidate_locks l LEFT JOIN hrm_candidates c ON c.id = l.candidate_id WHERE l.status = 'Active' AND l.expires_at > NOW() AND l.expires_at <= ?",
                     [date('Y-m-d H:i:s', time() + $days * 86400)]) as $l) {
            try { insert('hrm_notification_log', ['k' => 'lockrem:' . $l['id'] . ':' . date('Ymd', strtotime($l['expires_at']))]); } catch (PDOException $e) { continue; }
            lock_notify($l, 'Ownership expiring: ' . ($l['candidate'] ?? 'candidate'), "Your ownership of {$l['candidate']} for " . client_label((int) $l['client_id']) . ' ends on ' . fdate($l['expires_at'], 'd M Y, h:i A')
                . ". If the client is still considering the candidate, ask the recruitment team to extend it.");
            lock_event((int) $l['id'], 'reminder', ['candidate_id' => $l['candidate_id'], 'client_id' => $l['client_id'], 'vendor_id' => $l['vendor_id']], 'Expires ' . fdate($l['expires_at'], 'd M Y'));
            $n++;
        }
    }
    // 2. Expired: recheck the candidate's status before deciding.
    $hold = lock_hold_stages();
    foreach (all("SELECT l.*, c.name candidate FROM hrm_candidate_locks l LEFT JOIN hrm_candidates c ON c.id = l.candidate_id WHERE l.status IN ('Active','Held') AND l.expires_at <= NOW() ORDER BY l.id LIMIT 500") as $l) {
        $stage = lock_candidate_stage($l);
        $ctx = ['candidate_id' => $l['candidate_id'], 'client_id' => $l['client_id'], 'vendor_id' => $l['vendor_id']];
        if ($stage === 'Hired') {
            q("UPDATE hrm_candidate_locks SET status = 'Converted' WHERE id = ?", [$l['id']]);
            lock_event((int) $l['id'], 'converted', $ctx, 'Candidate hired: ownership kept');
        } elseif ($stage !== null && in_array($stage, $hold, true)) {
            if ($l['status'] !== 'Held') {
                q("UPDATE hrm_candidate_locks SET status = 'Held' WHERE id = ?", [$l['id']]);
                lock_event((int) $l['id'], 'held', $ctx, "Lock period ended while the candidate is at \"$stage\": held until the client process ends");
            } else continue;
        } else {
            lock_release((int) $l['id'], $stage === 'Rejected' ? 'Lock period ended (candidate not selected)' : 'Lock period ended', 'Expired', 'expired');
        }
        $n++;
    }
    // 3. Active locks whose candidate has since been hired are converted right away.
    foreach (all("SELECT l.* FROM hrm_candidate_locks l JOIN hrm_candidates c ON c.id = l.candidate_id WHERE l.status = 'Active' AND c.stage = 'Hired' LIMIT 200") as $l) {
        q("UPDATE hrm_candidate_locks SET status = 'Converted' WHERE id = ?", [$l['id']]);
        lock_event((int) $l['id'], 'converted', ['candidate_id' => $l['candidate_id'], 'client_id' => $l['client_id'], 'vendor_id' => $l['vendor_id']], 'Candidate hired: ownership kept');
        $n++;
    }
    return $n;
}

/* ═════════════════════════ Visibility ═════════════════════════ */

/** The live lock that protects a candidate record from this vendor (held by another partner for the same company), or null. */
function lock_blocking_vendor(int $vendorId, array $cand): ?array
{
    if (!lock_on()) return null;
    $clientId = (int) val('SELECT COALESCE(client_id, 0) FROM hrm_jobs WHERE id = ?', [(int) $cand['job_id']]);
    $m = lock_match($clientId, norm_email($cand['email'] ?? null), norm_phone($cand['phone'] ?? null));
    $l = $m['status'] === 'match' ? $m['lock'] : null;
    return $l && !in_array((int) $l['vendor_id'], vendor_group($vendorId), true) ? $l : null;
}

/** Every lock (any status, any company) that concerns a candidate's e-mail or mobile, newest first. */
function locks_for_contact(?string $email, ?string $phone): array
{
    $e = norm_email($email); $p = norm_phone($phone);
    if ($e === null && $p === null) return [];
    return all('SELECT l.*, v.name vendor, sv.name sub_vendor, j.title job, c.name candidate FROM hrm_candidate_locks l LEFT JOIN hrm_vendors v ON v.id = l.vendor_id
                LEFT JOIN hrm_vendors sv ON sv.id = l.sub_vendor_id LEFT JOIN hrm_jobs j ON j.id = l.job_id LEFT JOIN hrm_candidates c ON c.id = l.candidate_id
                WHERE (l.email_norm IS NOT NULL AND l.email_norm = ?) OR (l.phone_norm IS NOT NULL AND l.phone_norm = ?) ORDER BY FIELD(l.status, \'Active\', \'Held\', \'Converted\') DESC, l.id DESC',
        [$e ?? '', $p ?? '']);
}

function lock_counts(): array
{
    if (!plan_allows('manage_vendors') || !table_exists('hrm_ownership_reviews')) return ['lock_reviews' => 0];
    return ['lock_reviews' => can('manage_candidate_locks') ? (int) val("SELECT COUNT(*) FROM hrm_ownership_reviews WHERE status = 'Pending'") : 0];
}

/* ═════════════════════════ Reviews ═════════════════════════ */

function review_create(int $vendorId, array $job, array $data, string $note, array $match, ?int $userId): int
{
    [$owner, $sub] = vendor_owner($vendorId);
    $clientId = (int) ($job['client_id'] ?? 0);
    $id = insert('hrm_ownership_reviews', ['job_id' => (int) $job['id'], 'client_id' => $clientId, 'vendor_id' => $owner, 'sub_vendor_id' => $sub, 'submitted_by' => $userId,
        'payload' => json_encode(['data' => $data, 'note' => $note, 'submitter' => $vendorId], JSON_UNESCAPED_UNICODE), 'email_norm' => norm_email($data['email'] ?? null),
        'phone_norm' => norm_phone($data['phone'] ?? null), 'conflict' => mb_substr($match['why'], 0, 500), 'matched_lock_ids' => implode(',', array_column($match['locks'], 'id'))]);
    lock_event(null, 'review_opened', ['client_id' => $clientId, 'vendor_id' => $owner], "Review #$id: {$data['name']} for {$job['title']} ({$match['why']})");
    return $id;
}

/** Approve = different person: the submission enters the pipeline with its own lock. Reject = same person: the existing owner keeps it. */
function review_decide(int $id, bool $approve, string $note): ?int
{
    $r = one("SELECT * FROM hrm_ownership_reviews WHERE id = ? AND status = 'Pending'", [$id]);
    if (!$r) return null;
    $p = json_decode($r['payload'], true);
    $job = one('SELECT * FROM hrm_jobs WHERE id = ?', [$r['job_id']]);
    $ctx = ['client_id' => $r['client_id'], 'vendor_id' => $r['vendor_id']];
    $cid = null;
    if ($approve && $job) {
        $cid = lock_guard((int) $r['client_id'], function () use ($r, $p, $job, $note) {
            $cid = vendor_submission_insert((int) $p['submitter'], $job, $p['data'], (string) $p['note'], $r['submitted_by'] ? (int) $r['submitted_by'] : null);
            lock_create(['candidate_id' => $cid, 'email' => $r['email_norm'], 'phone' => $r['phone_norm'], 'client_id' => $r['client_id'], 'job_id' => $r['job_id'], 'vendor_id' => (int) $p['submitter'],
                'event' => 'review_approved', 'detail' => "Review #{$r['id']}: confirmed as a different person" . ($note !== '' ? ". $note" : '')]);
            return $cid;
        });
    } else {
        lock_event(null, 'review_rejected', $ctx, "Review #{$r['id']}: same person as the protected candidate" . ($note !== '' ? ". $note" : ''));
        if (!empty($p['data']['resume'])) @unlink(STORAGE_DIR . '/uploads/' . $p['data']['resume']);
    }
    q('UPDATE hrm_ownership_reviews SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?, candidate_id = ? WHERE id = ?',
        [$approve ? 'Approved' : 'Rejected', user()['id'] ?? null, mb_substr($note, 0, 500) ?: null, $cid, $id]);
    $fake = ['vendor_id' => $r['vendor_id'], 'sub_vendor_id' => $r['sub_vendor_id']];
    lock_notify($fake, 'Ownership review: ' . ($p['data']['name'] ?? 'candidate') . ($approve ? ' accepted' : ' not accepted'),
        $approve ? "Your submission of {$p['data']['name']} for {$job['title']} was reviewed and accepted. It is now in the pipeline and protected for your agency."
                 : "Your submission of {$p['data']['name']} for " . ($job['title'] ?? 'the job') . ' matches a candidate already protected for this client, so it was not accepted.' . ($note !== '' ? "\nNote: $note" : ''));
    return $cid ?? 0;
}

/* ═════════════════════════ Backfill (optional data update) ═════════════════════════ */

/** Recent vendor submissions without a lock: one per company + person (earliest wins). */
function lock_backfill_rows(): array
{
    if (!table_exists('hrm_candidate_locks')) return [];
    $since = date('Y-m-d H:i:s', time() - lock_days() * 86400);
    $rows = all("SELECT c.id, c.email, c.phone, c.vendor_id, c.job_id, c.created_at, COALESCE(j.client_id, 0) client_id FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id
                 WHERE c.vendor_id IS NOT NULL AND c.stage <> 'Rejected' AND c.created_at >= ? AND NOT EXISTS (SELECT 1 FROM hrm_candidate_locks l WHERE l.candidate_id = c.id) ORDER BY c.created_at, c.id", [$since]);
    $seen = $out = [];
    foreach ($rows as $r) {
        $e = norm_email($r['email']); $p = norm_phone($r['phone']);
        if ($e === null && $p === null) continue;
        $k1 = $r['client_id'] . '|e|' . $e; $k2 = $r['client_id'] . '|p|' . $p;
        if (($e && isset($seen[$k1])) || ($p && isset($seen[$k2]))) continue;
        if ($e) $seen[$k1] = 1;
        if ($p) $seen[$k2] = 1;
        $out[] = $r + ['e' => $e, 'p' => $p];
    }
    return $out;
}

function lock_backfill(): int
{
    $n = 0;
    foreach (lock_backfill_rows() as $r) {
        $n += (int) lock_guard((int) $r['client_id'], function () use ($r) {
            if (lock_match((int) $r['client_id'], $r['e'], $r['p'])['status'] !== 'none') return 0;
            lock_create(['candidate_id' => $r['id'], 'email' => $r['e'], 'phone' => $r['p'], 'client_id' => $r['client_id'], 'job_id' => $r['job_id'], 'vendor_id' => (int) $r['vendor_id'],
                'locked_at' => $r['created_at'], 'detail' => 'Created for a submission made before ownership locks existed']);
            return 1;
        });
    }
    return $n;
}
