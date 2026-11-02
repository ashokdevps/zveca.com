<?php
/**
 * Resdex subscriptions & database credits for client organisations.
 *
 *  - Plans (hrm_rdx_plans) are data: price, annual price, credits, duration, recruiter limit, feature flags.
 *  - One credit account per organisation; every change goes through credit_post(), which locks the account row
 *    (SELECT … FOR UPDATE) inside a transaction, refuses to go below zero and writes an append-only ledger entry whose
 *    txn_ref is unique — a repeated request (double click, retry, duplicate callback) can never charge or allocate twice.
 *  - Paid actions: job posting, profile unlock, CV download, Excel export (rates in Settings). Searching and masked
 *    previews are free. Unlocks are kept per organisation, but confidential data is only shown with an active subscription.
 *  - Orders reuse the Razorpay helpers (plans.php) and the client invoice / payment tables, so a paid order is a normal
 *    GST invoice in Accounts and in the client's Invoices page. Activation is idempotent (callback, webhook, manual entry).
 *  - rdx_tick(): annual plans' monthly allocations, renewals that start, expiry (credits expire when a plan lapses),
 *    reminders.
 */

const CREDIT_TYPES = ['allocation' => 'Plan credits', 'bonus' => 'Bonus credits', 'unlock' => 'Profile unlock', 'cv_download' => 'CV download', 'export' => 'Excel export',
    'job_post' => 'Job posting', 'adjustment' => 'Adjustment', 'reversal' => 'Reversal', 'expiry' => 'Credits expired', 'carry' => 'Carried forward'];

function rdx_on(): bool
{
    return setting('rdx_billing_enabled', '1') === '1';
}

/** Current rate of a paid action (configurable in Settings → Resdex credits). */
function rdx_cost(string $action): int
{
    $map = ['job_post' => ['rdx_cost_job_post', 5], 'unlock' => ['rdx_cost_unlock', 2], 'cv' => ['rdx_cost_cv', 2], 'export_new' => ['rdx_cost_export_new', 3], 'export_unlocked' => ['rdx_cost_export_unlocked', 2]];
    [$k, $d] = $map[$action];
    return max(0, (int) setting($k, $d));
}

function rdx_plan_decode(?array $p): ?array
{
    if (!$p) return null;
    $p['features'] = json_decode((string) $p['features'], true) ?: [];
    return $p;
}

function rdx_plans(bool $activeOnly = true): array
{
    return array_map('rdx_plan_decode', all('SELECT * FROM hrm_rdx_plans' . ($activeOnly ? " WHERE status = 'Active'" : '') . ' ORDER BY sort, price'));
}

function rdx_plan(int $id): ?array
{
    return rdx_plan_decode(one('SELECT * FROM hrm_rdx_plans WHERE id = ?', [$id]));
}

/** The organisation's active subscription (with plan name / features), or null. */
function rdx_sub(int $clientId): ?array
{
    $s = one("SELECT s.*, p.name plan_name, p.code plan_code FROM hrm_rdx_subscriptions s JOIN hrm_rdx_plans p ON p.id = s.plan_id
              WHERE s.client_id = ? AND s.status = 'active' AND s.starts_at <= NOW() AND s.expires_at > NOW() ORDER BY s.id DESC LIMIT 1", [$clientId]);
    if ($s) $s['features'] = json_decode((string) $s['features'], true) ?: [];
    return $s;
}

/** Feature of the organisation's plan: filters (basic|advanced|all), saved_searches, folders, notes, bulk_export, reports … */
function rdx_feature(int $clientId, string $f)
{
    $s = rdx_sub($clientId);
    if (!rdx_on()) return $f === 'filters' ? 'all' : true;
    if (!$s) return $f === 'filters' ? 'basic' : false;
    return $s['features'][$f] ?? ($f === 'filters' ? 'basic' : false);
}

function credit_balance(int $clientId): int
{
    return (int) val('SELECT balance FROM hrm_credit_accounts WHERE client_id = ?', [$clientId]);
}

/**
 * Adds (credits > 0) or deducts (credits < 0) credits atomically and records the ledger entry.
 * $o: user_id, subscription_id, candidate_id, job_id, export_id, order_id, reason, reverses_id.
 * Returns ['ok' => bool, 'id' => ledger id, 'balance' => new balance, 'duplicate' => bool, 'error' => ?string].
 */
function credit_post(int $clientId, int $credits, string $type, string $ref, array $o = []): array
{
    $pdo = db();
    $ref = mb_substr($ref, 0, 120);
    $own = !$pdo->inTransaction();
    // The account row is created outside the transaction: INSERT IGNORE inside it takes a shared lock that deadlocks
    // with parallel SELECT … FOR UPDATE. Deadlocks that still happen under heavy contention are retried.
    if ($own && !val('SELECT 1 FROM hrm_credit_accounts WHERE client_id = ?', [$clientId])) q('INSERT IGNORE INTO hrm_credit_accounts (client_id, balance) VALUES (?, 0)', [$clientId]);
    for ($attempt = 1; ; $attempt++) {
        try {
            return credit_post_tx($pdo, $own, $clientId, $credits, $type, $ref, $o);
        } catch (PDOException $e) {
            if ($own && $attempt < 5 && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) { usleep(random_int(20000, 120000)); continue; }
            throw $e;
        }
    }
}

function credit_post_tx(PDO $pdo, bool $own, int $clientId, int $credits, string $type, string $ref, array $o): array
{
    if ($own) $pdo->beginTransaction();
    try {
        if (!$own) q('INSERT IGNORE INTO hrm_credit_accounts (client_id, balance) VALUES (?, 0)', [$clientId]);
        $bal = (int) val('SELECT balance FROM hrm_credit_accounts WHERE client_id = ? FOR UPDATE', [$clientId]);
        if ($prev = one('SELECT id, balance_after FROM hrm_credit_ledger WHERE txn_ref = ?', [$ref])) {
            if ($own) $pdo->commit();
            return ['ok' => true, 'id' => (int) $prev['id'], 'balance' => $bal, 'duplicate' => true, 'error' => null];
        }
        if ($credits < 0 && $bal + $credits < 0) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'id' => null, 'balance' => $bal, 'duplicate' => false, 'error' => 'insufficient', 'short' => -($bal + $credits)];
        }
        $new = $bal + $credits;
        q('UPDATE hrm_credit_accounts SET balance = ? WHERE client_id = ?', [$new, $clientId]);
        $id = insert('hrm_credit_ledger', ['txn_ref' => $ref, 'client_id' => $clientId, 'user_id' => $o['user_id'] ?? (user()['id'] ?? null), 'subscription_id' => $o['subscription_id'] ?? null,
            'type' => $type, 'candidate_id' => $o['candidate_id'] ?? null, 'job_id' => $o['job_id'] ?? null, 'export_id' => $o['export_id'] ?? null, 'order_id' => $o['order_id'] ?? null,
            'credits' => $credits, 'balance_before' => $bal, 'balance_after' => $new, 'status' => 'completed', 'reason' => isset($o['reason']) ? mb_substr($o['reason'], 0, 255) : null,
            'reverses_id' => $o['reverses_id'] ?? null]);
        if ($own) $pdo->commit();
        return ['ok' => true, 'id' => $id, 'balance' => $new, 'duplicate' => false, 'error' => null];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) == 1062) { // the same reference committed by a parallel request
            $prev = one('SELECT id FROM hrm_credit_ledger WHERE txn_ref = ?', [$ref]);
            return ['ok' => true, 'id' => (int) ($prev['id'] ?? 0), 'balance' => credit_balance($clientId), 'duplicate' => true, 'error' => null];
        }
        throw $e;
    }
}

/** Gives back the credits of a failed operation as a separate reversal entry (the original entry stays as it was). */
function credit_reverse(int $ledgerId, string $reason): ?array
{
    $l = one('SELECT * FROM hrm_credit_ledger WHERE id = ?', [$ledgerId]);
    if (!$l || (int) $l['credits'] >= 0) return null;
    return credit_post((int) $l['client_id'], -(int) $l['credits'], 'reversal', 'rev:' . $ledgerId, ['reason' => $reason, 'reverses_id' => $ledgerId,
        'candidate_id' => $l['candidate_id'], 'job_id' => $l['job_id'], 'export_id' => $l['export_id'], 'subscription_id' => $l['subscription_id']]);
}

/** Why the organisation can't spend credits right now (null = it can). */
function rdx_spend_block(int $clientId): ?string
{
    if (!rdx_on()) return null;
    if (!rdx_sub($clientId)) return 'Your organisation has no active Resdex subscription. Choose a plan to unlock profiles, download CVs, export and post jobs.';
    return null;
}

/** Random, single-use idempotency key embedded in each confirmation form. */
function rdx_key(): string
{
    return bin2hex(random_bytes(12));
}

function rdx_log_access(int $clientId, ?int $candidateId, string $action, string $detail = ''): void
{
    insert('hrm_rdx_access_log', ['client_id' => $clientId, 'user_id' => user()['id'] ?? null, 'candidate_id' => $candidateId, 'action' => $action, 'detail' => $detail !== '' ? mb_substr($detail, 0, 255) : null, 'ip' => client_ip()]);
}

/* ───────────────────────── Paid actions ───────────────────────── */

function rdx_unlocked(int $clientId, int $candidateId): bool
{
    return (bool) val('SELECT 1 FROM hrm_rdx_unlocks WHERE client_id = ? AND candidate_id = ?', [$clientId, $candidateId]);
}

/** Confidential fields are visible when the profile was unlocked by this organisation AND the subscription is active. */
function rdx_contact_visible(int $clientId, int $candidateId): bool
{
    if (!rdx_on()) return rdx_unlocked($clientId, $candidateId);
    return rdx_unlocked($clientId, $candidateId) && rdx_sub($clientId) !== null;
}

/** A searchable candidate (in the index) — every paid action and profile view is limited to these. */
function rdx_indexed(int $candidateId): ?array
{
    return one('SELECT * FROM hrm_resdex_index WHERE candidate_id = ?', [$candidateId]);
}

/** Unlock contact: charged once per organisation per candidate (the reference is deterministic). */
function rdx_unlock(int $clientId, int $candidateId): array
{
    if (!rdx_indexed($candidateId)) return ['ok' => false, 'error' => 'This profile is no longer available.'];
    if (rdx_unlocked($clientId, $candidateId)) return ['ok' => true, 'charged' => 0, 'already' => true];
    if ($b = rdx_spend_block($clientId)) return ['ok' => false, 'error' => $b];
    $limit = (int) setting('rdx_daily_unlock_limit', 300);
    if ($limit > 0 && (int) val('SELECT COUNT(*) FROM hrm_rdx_unlocks WHERE client_id = ? AND created_at >= CURDATE()', [$clientId]) >= $limit) {
        return ['ok' => false, 'error' => "Daily unlock limit reached ($limit profiles). This protects candidates from bulk harvesting; please continue tomorrow or contact support."];
    }
    $cost = rdx_cost('unlock');
    $sub = rdx_sub($clientId);
    $r = credit_post($clientId, -$cost, 'unlock', "unlock:$clientId:$candidateId", ['candidate_id' => $candidateId, 'subscription_id' => $sub['id'] ?? null, 'reason' => 'Contact unlock']);
    if (!$r['ok']) return ['ok' => false, 'error' => 'insufficient', 'short' => $r['short'] ?? $cost];
    q('INSERT IGNORE INTO hrm_rdx_unlocks (client_id, candidate_id, user_id, ledger_id) VALUES (?, ?, ?, ?)', [$clientId, $candidateId, user()['id'] ?? null, $r['id']]);
    rdx_log_access($clientId, $candidateId, 'unlock', "-$cost credits");
    return ['ok' => true, 'charged' => $r['duplicate'] ? 0 : $cost, 'balance' => $r['balance']];
}

/** The resume of an indexed candidate (its own, or its careers-portal profile's). */
function rdx_resume(int $candidateId): ?string
{
    $c = one('SELECT c.resume, t.resume t_resume FROM hrm_candidates c LEFT JOIN hrm_talent t ON t.id = c.talent_id WHERE c.id = ?', [$candidateId]);
    $r = $c['resume'] ?: ($c['t_resume'] ?? null);
    return $r && is_file(STORAGE_DIR . '/uploads/' . $r) ? $r : null;
}

/** CV download: 2 credits; downloading the same CV version again is free (configurable). Returns a short-lived token. */
function rdx_cv(int $clientId, int $candidateId, string $key): array
{
    if (!rdx_indexed($candidateId)) return ['ok' => false, 'error' => 'This profile is no longer available.'];
    $resume = rdx_resume($candidateId);
    if (!$resume) return ['ok' => false, 'error' => 'This candidate has no CV on file.'];
    $free = setting('rdx_cv_redownload_free', '1') === '1' && val('SELECT 1 FROM hrm_rdx_cv_downloads WHERE client_id = ? AND candidate_id = ? AND resume = ?', [$clientId, $candidateId, $resume]);
    if ($b = rdx_spend_block($clientId)) return ['ok' => false, 'error' => $b];
    $charged = 0;
    if (!$free) {
        $cost = rdx_cost('cv');
        $ref = setting('rdx_cv_redownload_free', '1') === '1' ? "cv:$clientId:$candidateId:" . md5($resume) : "cv:$clientId:$candidateId:$key";
        $sub = rdx_sub($clientId);
        $r = credit_post($clientId, -$cost, 'cv_download', $ref, ['candidate_id' => $candidateId, 'subscription_id' => $sub['id'] ?? null, 'reason' => 'CV download']);
        if (!$r['ok']) return ['ok' => false, 'error' => 'insufficient', 'short' => $r['short'] ?? $cost];
        q('INSERT IGNORE INTO hrm_rdx_cv_downloads (client_id, candidate_id, resume, user_id, ledger_id) VALUES (?, ?, ?, ?, ?)', [$clientId, $candidateId, $resume, user()['id'] ?? null, $r['id']]);
        $charged = $r['duplicate'] ? 0 : $cost;
    }
    rdx_log_access($clientId, $candidateId, 'cv', $charged ? "-$charged credits" : 'free re-download');
    return ['ok' => true, 'charged' => $charged, 'token' => rdx_file_token($clientId, $candidateId, $resume)];
}

/** Signed, 10-minute download token bound to the organisation, candidate and file. */
function rdx_file_token(int $clientId, int $candidateId, string $resume): string
{
    $exp = time() + 600;
    return "$candidateId.$exp." . substr(hash_hmac('sha256', "rdxcv|$clientId|$candidateId|$resume|$exp", billing_secret()), 0, 32);
}

function rdx_file_from_token(int $clientId, string $token): ?string
{
    if (!preg_match('/^(\d+)\.(\d{10})\.([a-f0-9]{32})$/', $token, $m) || (int) $m[2] < time()) return null;
    $resume = rdx_resume((int) $m[1]);
    if (!$resume || !hash_equals(substr(hash_hmac('sha256', "rdxcv|$clientId|{$m[1]}|$resume|{$m[2]}", billing_secret()), 0, 32), $m[3])) return null;
    if (!val('SELECT 1 FROM hrm_rdx_cv_downloads WHERE client_id = ? AND candidate_id = ? AND resume = ?', [$clientId, (int) $m[1], $resume]) && rdx_on()) return null;
    return $resume;
}

/** Export cost for a selection: [ids (eligible), new ids, unlocked ids, total, invalid count]. */
function rdx_export_quote(int $clientId, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $ids = array_slice($ids, 0, max(1, (int) setting('rdx_export_max', 500)));
    $ok = $ids ? array_map('intval', array_column(all('SELECT candidate_id FROM hrm_resdex_index WHERE candidate_id IN (' . implode(',', $ids) . ')'), 'candidate_id')) : [];
    $unl = $ok ? array_map('intval', array_column(all('SELECT candidate_id FROM hrm_rdx_unlocks WHERE client_id = ? AND candidate_id IN (' . implode(',', $ok) . ')', [$clientId]), 'candidate_id')) : [];
    $new = array_values(array_diff($ok, $unl));
    $total = count($new) * rdx_cost('export_new') + count($unl) * rdx_cost('export_unlocked');
    return ['ids' => $ok, 'new' => $new, 'unlocked' => array_values($unl), 'total' => $total, 'invalid' => count($ids) - count($ok)];
}

/**
 * Charges and builds an export. The debit happens first (atomically); if building the file fails, the credits are
 * reversed. Exports never create unlock records. Returns ['ok', 'export_id', 'rows' => [[…]], 'header' => […]] or error.
 */
function rdx_export_run(int $clientId, array $ids, string $key): array
{
    if ($b = rdx_spend_block($clientId)) return ['ok' => false, 'error' => $b];
    if (!rdx_feature($clientId, 'bulk_export') && count($ids) > 25 && rdx_on()) return ['ok' => false, 'error' => 'Your plan exports up to 25 profiles at a time. Upgrade to Pro for bulk export.'];
    $q = rdx_export_quote($clientId, $ids);
    if (!$q['ids']) return ['ok' => false, 'error' => 'None of the selected profiles can be exported.'];
    $exportId = insert('hrm_rdx_exports', ['client_id' => $clientId, 'user_id' => user()['id'] ?? null, 'candidate_ids' => implode(',', $q['ids']), 'count_new' => count($q['new']), 'count_unlocked' => count($q['unlocked']), 'credits' => $q['total']]);
    $sub = rdx_sub($clientId);
    $r = credit_post($clientId, -$q['total'], 'export', "export:$clientId:$key", ['export_id' => $exportId, 'subscription_id' => $sub['id'] ?? null,
        'reason' => count($q['new']) . ' × ' . rdx_cost('export_new') . ' + ' . count($q['unlocked']) . ' × ' . rdx_cost('export_unlocked')]);
    if (!$r['ok']) {
        q("UPDATE hrm_rdx_exports SET status = 'rejected', error = 'Insufficient credits' WHERE id = ?", [$exportId]);
        return ['ok' => false, 'error' => 'insufficient', 'short' => $r['short'] ?? $q['total']];
    }
    if ($r['duplicate']) { // same confirmation submitted twice: serve the first export again without charging
        $prev = one('SELECT export_id FROM hrm_credit_ledger WHERE id = ?', [$r['id']]);
        q("UPDATE hrm_rdx_exports SET status = 'duplicate' WHERE id = ?", [$exportId]);
        $exportId = (int) $prev['export_id'];
        $q['ids'] = array_map('intval', explode(',', (string) val('SELECT candidate_ids FROM hrm_rdx_exports WHERE id = ?', [$exportId])));
    }
    try {
        $header = ['Candidate ID', 'Name', 'Designation', 'Current employer', 'Department', 'Experience (yrs)', 'Key skills', 'City', 'State', 'Education', 'Industry', 'Notice period', 'E-mail', 'Phone', 'LinkedIn'];
        $rows = [];
        foreach (all('SELECT x.*, c.email, c.phone, c.linkedin_url, i.name industry FROM hrm_resdex_index x JOIN hrm_candidates c ON c.id = x.candidate_id LEFT JOIN hrm_industries i ON i.id = x.industry_id
                      WHERE x.candidate_id IN (' . implode(',', $q['ids']) . ') ORDER BY x.name') as $x) {
            $rows[] = ['CAND-' . str_pad((string) $x['candidate_id'], 6, '0', STR_PAD_LEFT), $x['name'], $x['designation'], $x['employer'], $x['department'], $x['exp_years'], $x['skills'], $x['city'], $x['state'],
                $x['education'], $x['industry'], $x['notice_period'], $x['email'], $x['phone'], $x['linkedin_url']];
        }
        if (!$rows) throw new RuntimeException('No rows to export.');
        q("UPDATE hrm_rdx_exports SET status = 'completed', ledger_id = ? WHERE id = ? AND status <> 'duplicate'", [$r['id'], $exportId]);
        foreach ($q['ids'] as $cid) rdx_log_access($clientId, $cid, 'export', "export #$exportId");
        return ['ok' => true, 'export_id' => $exportId, 'header' => $header, 'rows' => $rows, 'charged' => $r['duplicate'] ? 0 : $q['total']];
    } catch (Throwable $e) {
        if (!$r['duplicate']) credit_reverse((int) $r['id'], 'Export failed: ' . $e->getMessage());
        q("UPDATE hrm_rdx_exports SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $exportId]);
        return ['ok' => false, 'error' => 'The export could not be generated. Your credits were not deducted.'];
    }
}

/** Job posting: charged once per job when it is first published (editing is free; reposting follows the setting). */
function rdx_job_charge(int $clientId, int $jobId, bool $repost = false): array
{
    if (!rdx_on()) return ['ok' => true, 'charged' => 0];
    $ref = $repost && setting('rdx_repost_charge', '0') === '1' ? "job:$jobId:repost:" . date('YmdHi') : "job:$jobId:post";
    if (!$repost && val('SELECT id FROM hrm_credit_ledger WHERE txn_ref = ?', [$ref])) return ['ok' => true, 'charged' => 0];
    if ($repost && setting('rdx_repost_charge', '0') !== '1') return ['ok' => true, 'charged' => 0];
    if ($b = rdx_spend_block($clientId)) return ['ok' => false, 'error' => $b];
    $cost = rdx_cost('job_post');
    $sub = rdx_sub($clientId);
    $r = credit_post($clientId, -$cost, 'job_post', $ref, ['job_id' => $jobId, 'subscription_id' => $sub['id'] ?? null, 'reason' => $repost ? 'Job reposted' : 'Job posting']);
    return $r['ok'] ? ['ok' => true, 'charged' => $r['duplicate'] ? 0 : $cost, 'ledger_id' => $r['id']] : ['ok' => false, 'error' => 'insufficient', 'short' => $r['short'] ?? $cost];
}

/* ───────────────────────── Recruiter seats ───────────────────────── */

const HIREFLOW_PERMS = ['client_post_jobs', 'client_pipeline', 'client_resdex'];

function rdx_recruiter_limit(int $clientId): int
{
    $s = rdx_sub($clientId);
    return $s ? max(1, (int) $s['recruiter_limit']) : 1;
}

/** Active client logins of the organisation that use HireFlow / Resdex (optionally excluding one login). */
function rdx_recruiters(int $clientId, int $exceptLogin = 0): array
{
    return array_values(array_filter(all("SELECT * FROM it_login WHERE client_id = ? AND LOWER(usertype) = 'client' AND status = 'active' AND id <> ?", [$clientId, $exceptLogin]),
        fn($l) => (bool) array_intersect(HIREFLOW_PERMS, permissions_for($l))));
}

/** Error when activating / adding a recruiter login would exceed the plan's seats, else null. */
function rdx_seat_error(int $clientId, int $exceptLogin = 0): ?string
{
    if (!rdx_on()) return null;
    $limit = rdx_recruiter_limit($clientId);
    $used = count(rdx_recruiters($clientId, $exceptLogin));
    return $used >= $limit ? "This organisation's plan allows $limit recruiter login" . ($limit > 1 ? 's' : '') . " and $used " . ($used === 1 ? 'is' : 'are') . ' already active. Upgrade the plan or deactivate a recruiter first.' : null;
}

/* ───────────────────────── Quotes, promotions, referrals ───────────────────────── */

function rdx_ref_code(int $clientId): string
{
    return 'REF-' . $clientId . '-' . strtoupper(substr(hash_hmac('sha256', "ref|$clientId", billing_secret()), 0, 4));
}

function rdx_ref_client(string $code): ?int
{
    if (!preg_match('/^REF-(\d+)-([A-Z0-9]{4})$/', strtoupper(trim($code)), $m)) return null;
    return hash_equals(rdx_ref_code((int) $m[1]), "REF-{$m[1]}-{$m[2]}") && val('SELECT id FROM hrm_clients WHERE id = ?', [(int) $m[1]]) ? (int) $m[1] : null;
}

function rdx_is_new_customer(int $clientId): bool
{
    return !val("SELECT id FROM hrm_rdx_orders WHERE client_id = ? AND status = 'paid' LIMIT 1", [$clientId]);
}

/** Why a promotion doesn't apply (null = eligible). */
function rdx_promo_block(array $p, int $clientId, array $plan, string $cycle): ?string
{
    if ($p['status'] !== 'Active') return 'This offer is not active.';
    if ($p['starts_at'] && $p['starts_at'] > date('Y-m-d H:i:s')) return 'This offer has not started yet.';
    if ($p['ends_at'] && $p['ends_at'] < date('Y-m-d H:i:s')) return 'This offer has ended.';
    if ($p['plan_codes'] && !in_array($plan['code'], explode(',', $p['plan_codes']), true)) return 'This offer does not apply to the ' . $plan['name'] . ' plan.';
    if (!in_array($cycle, explode(',', $p['cycles']), true)) return 'This offer does not apply to ' . $cycle . ' billing.';
    if ($p['max_redemptions'] && (int) val("SELECT COUNT(*) FROM hrm_promo_redemptions WHERE promotion_id = ? AND status = 'applied'", [$p['id']]) >= (int) $p['max_redemptions']) return 'This offer is fully redeemed.';
    if ($p['new_customers_only'] && !rdx_is_new_customer($clientId)) return 'This offer is for new customers only.';
    if ($p['verified_only'] && !val('SELECT verified_at FROM hrm_clients WHERE id = ?', [$clientId])) return 'This offer is for verified companies. Your company verification is pending.';
    if (val("SELECT id FROM hrm_promo_redemptions WHERE promotion_id = ? AND client_id = ? AND status = 'applied'", [$p['id'], $clientId])) return 'Your company has already used this offer.';
    return null;
}

/**
 * Server-side price for a plan purchase. Never trusts amounts from the browser.
 * Returns plan, cycle, list, discount, subtotal, tax (cgst/sgst/igst/rate), total, bonus credits, promos applied, referral, notes, errors.
 */
function rdx_quote(int $clientId, int $planId, string $cycle, string $code = ''): array
{
    $plan = rdx_plan($planId);
    $cycle = $cycle === 'annual' && $plan && $plan['annual_price'] !== null ? 'annual' : 'monthly';
    $q = ['plan' => $plan, 'cycle' => $cycle, 'list' => 0.0, 'discount' => 0.0, 'bonus' => 0, 'promos' => [], 'referral' => null, 'notes' => [], 'errors' => []];
    if (!$plan || $plan['status'] !== 'Active') { $q['errors'][] = 'Choose a plan.'; return $q; }
    $q['list'] = (float) ($cycle === 'annual' ? $plan['annual_price'] : $plan['price']);
    $price = null;
    $code = strtoupper(trim($code));
    // Referral code (another organisation's) or a promotion code.
    if ($code !== '' && str_starts_with($code, 'REF-')) {
        $ref = rdx_ref_client($code);
        $promo = one("SELECT * FROM hrm_promotions WHERE kind = 'referral' AND status = 'Active' ORDER BY id LIMIT 1");
        if (!$ref) $q['errors'][] = 'This referral code is not valid.';
        elseif ($ref === $clientId) $q['errors'][] = 'You can\'t use your own referral code.';
        elseif (!rdx_is_new_customer($clientId) || val('SELECT id FROM hrm_referrals WHERE referred_client_id = ? AND status = \'rewarded\'', [$clientId])) $q['errors'][] = 'Referral rewards are for new customers only.';
        elseif (rdx_same_company($ref, $clientId)) $q['errors'][] = 'Referrals between accounts of the same company are not eligible.';
        elseif ($promo && !($why = rdx_promo_block(array_merge($promo, ['verified_only' => 0]), $clientId, $plan, $cycle))) {
            $b = (int) ((json_decode((string) $promo['bonus'], true) ?: [])[$plan['code']] ?? 0);
            $q['bonus'] += $b;
            $q['promos'][] = ['id' => (int) $promo['id'], 'code' => $promo['code'], 'name' => $promo['name'], 'bonus' => $b, 'discount' => 0];
            $q['referral'] = $ref;
            $q['notes'][] = "Referral: $b bonus credits for you and " . (int) $promo['referrer_bonus'] . ' for the company that referred you, after payment.';
        } elseif (isset($why)) $q['errors'][] = $why;
    } elseif ($code !== '') {
        $p = one("SELECT * FROM hrm_promotions WHERE code = ? AND kind IN ('price','bonus')", [$code]);
        if (!$p) $q['errors'][] = 'This offer code is not valid.';
        elseif ($why = rdx_promo_block($p, $clientId, $plan, $cycle)) $q['errors'][] = $why;
        else {
            if ($p['kind'] === 'price') {
                $price = (float) ((json_decode((string) $p['price'], true) ?: [])[$plan['code']] ?? $q['list']);
                $disc = max(0, $q['list'] - $price);
                $q['discount'] += $disc;
                $q['promos'][] = ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'bonus' => 0, 'discount' => $disc];
                $q['notes'][] = $p['name'] . ': first ' . ($cycle === 'annual' ? 'year' : 'month') . ' at ' . money($price) . '.';
            } else {
                $b = (int) ((json_decode((string) $p['bonus'], true) ?: [])[$plan['code']] ?? 0);
                $q['bonus'] += $b;
                $q['promos'][] = ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'bonus' => $b, 'discount' => 0];
            }
        }
    }
    // Automatic offers (launch bonus): full listed price only, and never on top of a price discount.
    foreach (all("SELECT * FROM hrm_promotions WHERE auto_apply = 1 AND kind = 'bonus' AND status = 'Active'") as $p) {
        if ($q['discount'] > 0 || in_array((int) $p['id'], array_column($q['promos'], 'id'), true) || rdx_promo_block($p, $clientId, $plan, $cycle)) continue;
        $b = (int) ((json_decode((string) $p['bonus'], true) ?: [])[$plan['code']] ?? 0);
        if ($b <= 0) continue;
        $q['bonus'] += $b;
        $q['promos'][] = ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'bonus' => $b, 'discount' => 0];
        $q['notes'][] = $p['name'] . ": +$b bonus credits after payment.";
    }
    $q['subtotal'] = round($q['list'] - $q['discount'], 2);
    $client = one('SELECT state FROM hrm_clients WHERE id = ?', [$clientId]);
    $rate = $plan['gst_applicable'] ? (float) setting('rdx_gst_percent', gst_rate()) : 0.0;
    $q['tax'] = invoice_tax($q['subtotal'], $rate, $client['state'] ?: company_state()) + ['rate' => $rate];
    $q['total'] = round($q['subtotal'] + $q['tax']['total_tax'], 2);
    $q['credits'] = (int) $plan['credits'];
    $q['allocations'] = $cycle === 'annual' ? 12 : 1;
    return $q;
}

/** Same company behind two client records (same GSTIN or same non-public e-mail domain): no referral reward. */
function rdx_same_company(int $a, int $b): bool
{
    $x = one('SELECT gst_no, email FROM hrm_clients WHERE id = ?', [$a]);
    $y = one('SELECT gst_no, email FROM hrm_clients WHERE id = ?', [$b]);
    if (!$x || !$y) return false;
    if ($x['gst_no'] && $y['gst_no'] && strtoupper($x['gst_no']) === strtoupper($y['gst_no'])) return true;
    $d = fn($e) => strtolower(substr(strrchr((string) $e, '@') ?: '', 1));
    $public = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'rediffmail.com', 'yahoo.co.in', 'icloud.com', 'live.com'];
    return $d($x['email']) !== '' && $d($x['email']) === $d($y['email']) && !in_array($d($x['email']), $public, true);
}

/* ───────────────────────── Orders & activation ───────────────────────── */

function rdx_order_create(int $clientId, int $planId, string $cycle, string $code): array
{
    $q = rdx_quote($clientId, $planId, $cycle, $code);
    if ($q['errors']) return ['ok' => false, 'errors' => $q['errors']];
    $id = insert('hrm_rdx_orders', ['client_id' => $clientId, 'user_id' => user()['id'] ?? null, 'plan_id' => $planId, 'cycle' => $q['cycle'], 'list_price' => $q['list'], 'discount' => $q['discount'],
        'subtotal' => $q['subtotal'], 'tax_rate' => $q['tax']['rate'], 'cgst' => $q['tax']['cgst'], 'sgst' => $q['tax']['sgst'], 'igst' => $q['tax']['igst'], 'total' => $q['total'],
        'bonus_credits' => $q['bonus'], 'promo_ids' => implode(',', array_column($q['promos'], 'id')) ?: null, 'status' => 'created', 'gateway' => payments_enabled() ? 'razorpay' : 'manual']);
    $no = 'RDX' . date('ym') . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    q('UPDATE hrm_rdx_orders SET order_no = ? WHERE id = ?', [$no, $id]);
    foreach ($q['promos'] as $p) {
        // One redemption per company per promotion; a pending one moves to the newest order.
        q('INSERT INTO hrm_promo_redemptions (promotion_id, client_id, order_id, status, bonus_credits, discount) VALUES (?, ?, ?, \'pending\', ?, ?)
           ON DUPLICATE KEY UPDATE order_id = IF(status = \'applied\', order_id, VALUES(order_id)), bonus_credits = IF(status = \'applied\', bonus_credits, VALUES(bonus_credits)), discount = IF(status = \'applied\', discount, VALUES(discount))',
            [$p['id'], $clientId, $id, $p['bonus'], $p['discount']]);
    }
    if ($q['referral']) {
        q('INSERT INTO hrm_referrals (referrer_client_id, referred_client_id, order_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE order_id = IF(status = \'pending\', VALUES(order_id), order_id)', [$q['referral'], $clientId, $id]);
        q('UPDATE hrm_rdx_orders SET referral_id = (SELECT id FROM hrm_referrals WHERE referred_client_id = ?) WHERE id = ?', [$clientId, $id]);
    }
    log_activity('order_created', 'rdx_order', $id, "$no · {$q['plan']['name']} {$q['cycle']} · " . money($q['total']));
    return ['ok' => true, 'id' => $id, 'order' => one('SELECT * FROM hrm_rdx_orders WHERE id = ?', [$id])];
}

/**
 * Marks an order paid exactly once (checkout callback, webhook and manual entry can race) and then activates the
 * subscription, allocates credits and bonuses, records the GST invoice + payment and rewards a referral.
 * Returns true if this call did the transition.
 */
function rdx_order_mark_paid(int $orderId, string $paymentId, string $method, string $source, string $reference = ''): bool
{
    $changed = q("UPDATE hrm_rdx_orders SET status = 'paid', gateway_payment_id = COALESCE(?, gateway_payment_id), payment_method = ?, payment_ref = ?, paid_at = NOW(), failure_reason = NULL
                  WHERE id = ? AND status IN ('created','pending','failed')", [$paymentId ?: null, $method ?: null, $reference ?: null, $orderId])->rowCount() === 1;
    if (!$changed) return false;
    $o = one('SELECT * FROM hrm_rdx_orders WHERE id = ?', [$orderId]);
    $plan = rdx_plan((int) $o['plan_id']);
    $subId = rdx_activate($o, $plan);
    // GST invoice + payment in the client's accounts (so it appears under Invoices for both sides).
    try {
        $place = (string) (val('SELECT state FROM hrm_clients WHERE id = ?', [$o['client_id']]) ?: company_state());
        $invId = insert('hrm_invoices', ['invoice_no' => next_client_invoice_no(), 'client_id' => $o['client_id'], 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'),
            'amount' => $o['subtotal'], 'tax_percent' => $o['tax_rate'], 'place_of_supply' => $place, 'cgst' => $o['cgst'], 'sgst' => $o['sgst'], 'igst' => $o['igst'], 'total' => $o['total'],
            'paid' => 0, 'status' => 'Unpaid', 'notes' => 'Resdex subscription · order ' . $o['order_no']]);
        save_invoice_items($invId, [['description' => "Resdex {$plan['name']} plan — " . ($o['cycle'] === 'annual' ? '12 months' : $plan['duration_days'] . ' days') . ', ' . $plan['credits'] . ' credits' . ($o['cycle'] === 'annual' ? ' per month' : '')
            . ((float) $o['discount'] > 0 ? ' (offer applied)' : ''), 'sac' => setting('rdx_sac', '') ?: setting('client_invoice_sac', '998313'), 'qty' => 1, 'rate' => $o['subtotal'], 'amount' => $o['subtotal']]]);
        insert('hrm_payments', ['invoice_id' => $invId, 'amount' => $o['total'], 'paid_on' => date('Y-m-d'), 'method' => $method ?: ($source === 'manual' ? 'Bank transfer' : 'Razorpay'), 'reference' => mb_substr($paymentId ?: $reference, 0, 120) ?: null, 'notes' => 'Resdex order ' . $o['order_no']]);
        refresh_invoice($invId);
        q('UPDATE hrm_rdx_orders SET invoice_no = (SELECT invoice_no FROM hrm_invoices WHERE id = ?) WHERE id = ?', [$invId, $orderId]);
    } catch (Throwable $e) {
        error_log('Resdex order invoice: ' . $e->getMessage());
    }
    // Promotions and referral rewards (only now, after the qualifying payment).
    foreach (all("SELECT r.*, p.kind, p.referrer_bonus, p.name FROM hrm_promo_redemptions r JOIN hrm_promotions p ON p.id = r.promotion_id WHERE r.order_id = ? AND r.status = 'pending'", [$orderId]) as $r) {
        q("UPDATE hrm_promo_redemptions SET status = 'applied' WHERE id = ?", [$r['id']]);
        if ((int) $r['bonus_credits'] > 0) credit_post((int) $o['client_id'], (int) $r['bonus_credits'], 'bonus', "promo:{$r['id']}:bonus", ['subscription_id' => $subId, 'order_id' => $orderId, 'reason' => $r['name']]);
    }
    if ($o['referral_id'] && ($ref = one("SELECT * FROM hrm_referrals WHERE id = ? AND status = 'pending'", [$o['referral_id']]))) {
        $bonus = (int) val("SELECT referrer_bonus FROM hrm_promotions WHERE kind = 'referral' ORDER BY id LIMIT 1");
        q("UPDATE hrm_referrals SET status = 'rewarded', rewarded_at = NOW(), order_id = ? WHERE id = ?", [$orderId, $ref['id']]);
        if ($bonus > 0) credit_post((int) $ref['referrer_client_id'], $bonus, 'bonus', "referral:{$ref['id']}:referrer", ['reason' => 'Referral reward: ' . (string) val('SELECT name FROM hrm_clients WHERE id = ?', [$o['client_id']])]);
    }
    log_activity('order_paid', 'rdx_order', $orderId, "{$o['order_no']} · " . money($o['total']) . " · via $source");
    rdx_notify_client((int) $o['client_id'], "Payment received: Resdex {$plan['name']} ({$o['order_no']})", "Thank you. We received " . money($o['total'], true) . " for the Resdex {$plan['name']} plan.\n\n"
        . 'Credit balance: ' . credit_balance((int) $o['client_id']) . "\nValid until: " . fdate((string) val('SELECT expires_at FROM hrm_rdx_subscriptions WHERE id = ?', [$subId]), 'd M Y') . "\n\nYour GST invoice is in the client portal under Invoices.");
    return true;
}

/** Creates the subscription for a paid order (new, renewal queued after the current one, or immediate upgrade). */
function rdx_activate(array $o, array $plan): int
{
    $cur = rdx_sub((int) $o['client_id']);
    $annual = $o['cycle'] === 'annual';
    $start = date('Y-m-d H:i:s');
    $status = 'active';
    if ($cur && (int) $cur['plan_id'] === (int) $plan['id'] && $cur['cycle'] === $o['cycle']) {
        $start = $cur['expires_at']; // renewal paid early: starts when the current period ends
        $status = 'scheduled';
    } elseif ($cur) {
        q("UPDATE hrm_rdx_subscriptions SET status = 'upgraded', expires_at = NOW(), note = ? WHERE id = ?", ['Replaced by order ' . $o['order_no'], $cur['id']]);
        if (setting('rdx_carry_forward', '1') !== '1' && ($bal = credit_balance((int) $o['client_id'])) > 0) {
            credit_post((int) $o['client_id'], -$bal, 'expiry', "sub:{$cur['id']}:upgrade-expiry", ['subscription_id' => $cur['id'], 'reason' => 'Plan changed (no carry-forward)']);
        }
    }
    $expires = date('Y-m-d H:i:s', strtotime($start . ($annual ? ' +1 year' : ' +' . (int) $plan['duration_days'] . ' days')));
    $id = insert('hrm_rdx_subscriptions', ['client_id' => $o['client_id'], 'plan_id' => $plan['id'], 'order_id' => $o['id'], 'cycle' => $o['cycle'], 'status' => $status, 'starts_at' => $start, 'expires_at' => $expires,
        'credits_per_period' => $plan['credits'], 'allocations_total' => $annual ? 12 : 1, 'allocations_done' => 0, 'next_allocation_at' => $start, 'recruiter_limit' => $plan['recruiter_limit'],
        'features' => json_encode($plan['features']), 'created_by' => user()['id'] ?? null]);
    q('UPDATE hrm_rdx_orders SET subscription_id = ? WHERE id = ?', [$id, $o['id']]);
    if ($status === 'active') rdx_allocate_due($id);
    return $id;
}

/** Allocates the plan credits that are due for a subscription (monthly for annual plans). Idempotent per allocation number. */
function rdx_allocate_due(int $subId): int
{
    $n = 0;
    while (($s = one("SELECT * FROM hrm_rdx_subscriptions WHERE id = ? AND status = 'active' AND allocations_done < allocations_total AND next_allocation_at <= NOW()", [$subId])) && $n < 13) {
        $k = (int) $s['allocations_done'] + 1;
        credit_post((int) $s['client_id'], (int) $s['credits_per_period'], 'allocation', "sub:$subId:alloc:$k", ['subscription_id' => $subId, 'reason' => 'Plan credits' . ($s['allocations_total'] > 1 ? " (month $k of {$s['allocations_total']})" : '')]);
        q('UPDATE hrm_rdx_subscriptions SET allocations_done = ?, next_allocation_at = ? WHERE id = ? AND allocations_done = ?', [$k, date('Y-m-d H:i:s', strtotime($s['next_allocation_at'] . ' +1 month')), $subId, $k - 1]);
        $n++;
    }
    return $n;
}

/** Complimentary / manual subscription by an administrator (e.g. enterprise contract), with audit. */
function rdx_grant(int $clientId, int $planId, string $cycle, string $note): int
{
    $plan = rdx_plan($planId);
    $o = ['id' => null, 'client_id' => $clientId, 'cycle' => $cycle === 'annual' ? 'annual' : 'monthly', 'order_no' => 'grant'];
    $id = rdx_activate($o, $plan);
    q('UPDATE hrm_rdx_subscriptions SET note = ? WHERE id = ?', [mb_substr('Granted by ' . (user()['username'] ?? 'admin') . ': ' . $note, 0, 255), $id]);
    log_activity('subscription_granted', 'rdx_subscription', $id, "{$plan['name']} · client #$clientId · $note");
    return $id;
}

function rdx_notify_client(int $clientId, string $subject, string $body): void
{
    $to = array_unique(array_filter(array_merge([(string) val('SELECT email FROM hrm_clients WHERE id = ?', [$clientId])],
        array_column(all("SELECT email FROM it_login WHERE client_id = ? AND status = 'active' AND is_signatory = 1", [$clientId]), 'email'))));
    foreach ($to as $addr) {
        try { send_mail($addr, $subject, "Hello,\n\n$body\n\n— " . setting('company_name', 'Adorable IT Services')); } catch (Throwable $e) { error_log('Resdex mail: ' . $e->getMessage()); }
    }
}

/* ───────────────────────── Scheduled work ───────────────────────── */

function rdx_tick(bool $force = false): int
{
    if (!$force) {
        if (time() - (int) setting('rdx_tick_at', 0) < 600) return 0;
        set_setting('rdx_tick_at', time());
    }
    $n = 0;
    // 1. Monthly allocations of annual plans.
    foreach (array_column(all("SELECT id FROM hrm_rdx_subscriptions WHERE status = 'active' AND allocations_done < allocations_total AND next_allocation_at <= NOW()"), 'id') as $id) $n += rdx_allocate_due((int) $id);
    // 2. Expiry, then queued renewals.
    foreach (all("SELECT * FROM hrm_rdx_subscriptions WHERE status = 'active' AND expires_at <= NOW()") as $s) {
        q("UPDATE hrm_rdx_subscriptions SET status = 'expired' WHERE id = ? AND status = 'active'", [$s['id']]);
        $next = one("SELECT * FROM hrm_rdx_subscriptions WHERE client_id = ? AND status = 'scheduled' ORDER BY starts_at LIMIT 1", [$s['client_id']]);
        $bal = credit_balance((int) $s['client_id']);
        if ($bal > 0 && (!$next || setting('rdx_carry_forward', '1') !== '1')) {
            credit_post((int) $s['client_id'], -$bal, 'expiry', "sub:{$s['id']}:expiry", ['subscription_id' => $s['id'], 'reason' => $next ? 'Plan period ended (no carry-forward)' : 'Plan expired: unused credits expire']);
        }
        if (!$next) rdx_notify_client((int) $s['client_id'], 'Your Resdex subscription has expired', 'Your Resdex plan expired on ' . fdate($s['expires_at'], 'd M Y') . ". Contact details are hidden again and paid actions are paused. Profiles you unlocked stay unlocked and won't be charged again after you renew.");
        $n++;
    }
    foreach (all("SELECT * FROM hrm_rdx_subscriptions WHERE status = 'scheduled' AND starts_at <= NOW()") as $s) {
        if (rdx_sub((int) $s['client_id'])) continue;
        q("UPDATE hrm_rdx_subscriptions SET status = 'active' WHERE id = ?", [$s['id']]);
        $n += rdx_allocate_due((int) $s['id']);
    }
    // 3. Expiry reminders (once per subscription and day count).
    foreach (array_filter(array_map('intval', explode(',', (string) setting('rdx_expiry_reminder_days', '7,1')))) as $d) {
        foreach (all("SELECT s.*, p.name plan_name FROM hrm_rdx_subscriptions s JOIN hrm_rdx_plans p ON p.id = s.plan_id WHERE s.status = 'active' AND s.expires_at > NOW() AND s.expires_at <= ?
                      AND NOT EXISTS (SELECT 1 FROM hrm_rdx_subscriptions n WHERE n.client_id = s.client_id AND n.status = 'scheduled')", [date('Y-m-d H:i:s', time() + $d * 86400)]) as $s) {
            try { insert('hrm_notification_log', ['k' => "rdxexp:{$s['id']}:$d"]); } catch (PDOException $e) { continue; }
            rdx_notify_client((int) $s['client_id'], "Resdex {$s['plan_name']} expires in $d day" . ($d > 1 ? 's' : ''), 'Your plan expires on ' . fdate($s['expires_at'], 'd M Y, h:i A') . '. Credit balance: ' . credit_balance((int) $s['client_id'])
                . (setting('rdx_carry_forward', '1') === '1' ? '. Renew before it expires to carry your unused credits forward.' : '. Unused credits expire with the plan.'));
            $n++;
        }
    }
    return $n;
}

/** Human-readable credit policy shown on the billing page. */
function rdx_policy_text(): array
{
    $carry = setting('rdx_carry_forward', '1') === '1';
    return [
        'Credits are used for: job posting ' . rdx_cost('job_post') . ', contact unlock ' . rdx_cost('unlock') . ', CV download ' . rdx_cost('cv') . ', Excel export ' . rdx_cost('export_new') . ' per profile (' . rdx_cost('export_unlocked') . ' if already unlocked). Searching and previews are free.',
        $carry ? 'Unused credits carry forward when you renew or upgrade before your plan expires. If the plan lapses, unused credits expire on the expiry date.' : 'Unused credits expire at the end of each plan period.',
        'Annual plans include 12 monthly credit allocations (on the start date and every month after).',
        'An unlocked profile is never charged again for its unlock, but contact details are shown only while your subscription is active.',
        'Plans renew manually: renew any time before expiry and the new period starts when the current one ends. Upgrades start immediately.',
        'Prices exclude GST, which is added at the rate configured for your invoice. Subscriptions are non-refundable once credits have been used; see the refund policy.',
    ];
}
