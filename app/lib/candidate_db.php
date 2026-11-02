<?php
/**
 * Central candidate database (Candidates dashboard = source of truth).
 *
 *  Person vs. application: hrm_candidates.master_id IS NULL marks the candidate master record (one per person); further
 *  applications of the same person to other jobs are rows linked to it (master_id = master's id). Duplicate detection,
 *  the identity registry and the Resdex index only ever consider master records.
 *
 *  Identity: normalised e-mail (trimmed, lower-case, validated — no provider-specific rewriting) and phone in E.164
 *  (country-aware; numbers that can't be placed safely are "ambiguous" and never auto-matched). A master record is a
 *  potential duplicate when EITHER identifier matches another master (OR logic). Creation is serialised with a named
 *  database lock and the identity registry's UNIQUE key, so two simultaneous requests can't create the same person.
 *
 *  Resdex: eligible masters are copied (approved, non-confidential fields only) into hrm_resdex_index after every write
 *  (rdx_sync), with retries for failures from the background tick (cand_tick). Contact details, LinkedIn and resumes are
 *  never part of the index.
 */

/* ───────────────────────── Normalisation ───────────────────────── */

/** Trimmed, lower-cased, validated e-mail, or null. Deliberately no Gmail dot / "+tag" rewriting. */
function cand_email_norm(?string $e): ?string
{
    $e = strtolower(trim((string) $e));
    return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) ? mb_substr($e, 0, 190) : null;
}

/** Calling codes with valid national-number lengths (ISO country => [code, [lengths]]). */
const PHONE_COUNTRIES = [
    'IN' => ['91', [10]], 'US' => ['1', [10]], 'GB' => ['44', [10]], 'AE' => ['971', [8, 9]], 'SA' => ['966', [9]], 'QA' => ['974', [8]], 'OM' => ['968', [8]],
    'KW' => ['965', [8]], 'BH' => ['973', [8]], 'SG' => ['65', [8]], 'MY' => ['60', [9, 10]], 'AU' => ['61', [9]], 'NZ' => ['64', [8, 9, 10]], 'DE' => ['49', [10, 11]],
    'FR' => ['33', [9]], 'NL' => ['31', [9]], 'IE' => ['353', [9]], 'ZA' => ['27', [9]], 'NP' => ['977', [10]], 'BD' => ['880', [10]], 'LK' => ['94', [9]], 'PK' => ['92', [10]],
    'PH' => ['63', [10]], 'ID' => ['62', [9, 10, 11, 12]], 'JP' => ['81', [10]], 'CN' => ['86', [11]], 'HK' => ['852', [8]], 'KE' => ['254', [9]], 'NG' => ['234', [10]],
    'CH' => ['41', [9]], 'SE' => ['46', [9]], 'IT' => ['39', [9, 10]], 'ES' => ['34', [9]], 'CA' => ['1', [10]], 'TH' => ['66', [9]], 'VN' => ['84', [9, 10]], 'EG' => ['20', [10]],
];

/**
 * Phone → E.164. ['e164' => '+919876543210'|null, 'country' => 'IN'|null, 'status' => ok|empty|invalid|ambiguous, 'national' => …]
 * With a "+" (or 00) the calling code decides. Without one, only numbers that clearly fit the default country are accepted
 * (India: 10 digits starting 6–9, optionally with 0 / 91 in front); anything else is "ambiguous" and must be corrected or reviewed.
 */
function phone_e164(?string $raw, ?string $default = null): array
{
    $raw = trim((string) $raw);
    $out = ['e164' => null, 'country' => null, 'status' => 'empty', 'national' => null];
    if ($raw === '') return $out;
    $s = preg_replace('/(?:ext\.?|x|#)\s*\d+$/i', '', $raw);                 // drop extensions
    $plus = (bool) preg_match('/^\s*(\+|00)/', $s);
    $digits = preg_replace('/\D+/', '', $s);
    if ($plus && str_starts_with(ltrim($s), '00')) $digits = substr($digits, 2);
    if (strlen($digits) < 7 || strlen($digits) > 15) return ['status' => 'invalid'] + $out;
    if ($plus) {
        foreach ([3, 2, 1] as $n) {
            $cc = substr($digits, 0, $n);
            $nsn = substr($digits, $n);
            foreach (PHONE_COUNTRIES as $iso => [$code, $lens]) {
                if ($code !== $cc || !in_array(strlen($nsn), $lens, true)) continue;
                if ($iso === 'IN' && !preg_match('/^[2-9]/', $nsn)) return ['status' => 'invalid'] + $out;
                return ['e164' => '+' . $digits, 'country' => $iso, 'status' => 'ok', 'national' => $nsn];
            }
        }
        // Unknown calling code but a plausible international length: E.164 is still exact, so it is safe to match.
        return strlen($digits) >= 8 ? ['e164' => '+' . $digits, 'country' => null, 'status' => 'ok', 'national' => null] : ['status' => 'invalid'] + $out;
    }
    $def = strtoupper($default ?? (string) setting('default_phone_country', 'IN'));
    if ($def === 'IN') {
        if (preg_match('/^(?:0|91)?([6-9]\d{9})$/', $digits, $m)) return ['e164' => '+91' . $m[1], 'country' => 'IN', 'status' => 'ok', 'national' => $m[1]];
        return ['status' => 'ambiguous'] + $out; // landline without code, foreign number without "+", short number …
    }
    if (isset(PHONE_COUNTRIES[$def])) {
        [$code, $lens] = PHONE_COUNTRIES[$def];
        $nsn = ltrim($digits, '0');
        if (str_starts_with($digits, $code) && in_array(strlen($digits) - strlen($code), $lens, true)) $nsn = substr($digits, strlen($code));
        if (in_array(strlen($nsn), $lens, true)) return ['e164' => '+' . $code . $nsn, 'country' => $def, 'status' => 'ok', 'national' => $nsn];
    }
    return ['status' => 'ambiguous'] + $out;
}

/** Identity of supplied data: normalised e-mail + phone and an overall status. */
function cand_identity(?string $email, ?string $phone): array
{
    $e = cand_email_norm($email);
    $p = phone_e164($phone);
    $status = 'ok';
    $issues = [];
    if (trim((string) $email) !== '' && $e === null) { $status = 'invalid_email'; $issues[] = 'The e-mail address is not valid.'; }
    if ($p['status'] === 'invalid') { $status = $status === 'ok' ? 'invalid_phone' : $status; $issues[] = 'The phone number is not valid.'; }
    if ($p['status'] === 'ambiguous') { $status = $status === 'ok' ? 'ambiguous_phone' : $status; $issues[] = 'The phone number needs a country code (e.g. +971 …) so it can be checked safely.'; }
    if ($e === null && $p['e164'] === null && $status === 'ok') $status = 'no_identifier';
    return ['email' => $e, 'phone' => $p['e164'], 'phone_country' => $p['country'], 'phone_status' => $p['status'], 'status' => $status, 'issues' => $issues];
}

/* ───────────────────────── Derived profile values ───────────────────────── */

const QUAL_LEVELS = [1 => 'Secondary (10th)', 2 => 'Senior secondary (12th)', 3 => 'Diploma / ITI', 4 => "Bachelor's degree", 5 => "Master's / PG diploma", 6 => 'Doctorate (PhD)'];

/** Qualification level (QUAL_LEVELS) from free text such as "B.Tech (CSE), AKTU, 2018" or "MBA". */
function qualification_level_of(?string $t): ?int
{
    $t = ' ' . strtolower((string) $t) . ' ';
    if (trim($t) === '') return null;
    $rules = [6 => '/\b(ph\.?\s?d|doctorate|d\.?phil)\b/', 5 => '/\b(m\.?\s?tech|m\.?e\b|mba|pgdm|pgdba|mca|m\.?\s?sc|m\.?\s?com|m\.?\s?a\b|m\.?\s?s\b|ms\b|master|post\s*graduat|m\.?\s?pharm|llm|md\b|ca\b|chartered accountant)/',
        4 => '/\b(b\.?\s?tech|b\.?\s?e\b|bca|b\.?\s?sc|b\.?\s?com|bba|bms|b\.?\s?a\b|bachelor|graduat|b\.?\s?pharm|llb|mbbs|bds|b\.?\s?arch|b\.?\s?ed)/',
        3 => '/\b(diploma|polytechnic|iti)\b/', 2 => '/\b(12th|xii|hsc|intermediate|senior secondary|higher secondary|plus two)\b/', 1 => '/\b(10th|x\b|ssc|matric|secondary school|high school)\b/'];
    foreach ($rules as $lvl => $re) if (preg_match($re, $t)) return $lvl;
    return null;
}

/**
 * Salary text → [annual amount, currency, period]. "6 LPA", "6.5 lakh", "12L", "₹ 6,00,000", "50k per month", "1.2 Cr", "$80k".
 * Returns [null, …] when the text has no number.
 */
function ctc_to_annual(?string $t): array
{
    $s = strtolower(trim((string) $t));
    if ($s === '' || !preg_match('/(\d+(?:[.,]\d+)*)/', $s, $m)) return [null, null, null];
    $currency = preg_match('/\$|usd/', $s) ? 'USD' : (preg_match('/aed|dirham/', $s) ? 'AED' : (preg_match('/€|eur/', $s) ? 'EUR' : (preg_match('/£|gbp/', $s) ? 'GBP' : 'INR')));
    $num = (float) str_replace(',', '', $m[1]);
    if (preg_match('/\b(cr|crore)s?\b/', $s)) $num *= 10000000;
    elseif (preg_match('/(lpa|lakh|lac|\bl\b|\d\s*l\b)/', $s)) $num *= 100000;
    elseif (preg_match('/\d\s*k\b|\bk\b|thousand/', $s)) $num *= 1000;
    elseif (preg_match('/\b(m|mn|million)\b/', $s)) $num *= 1000000;
    $period = preg_match('/month|\bpm\b|p\.m\.|\/m\b|per mon/', $s) ? 'Monthly' : 'Annual';
    if ($period === 'Monthly') $num *= 12;
    elseif ($currency === 'INR' && $num > 0 && $num < 100) $num *= 100000; // a bare "6" or "6.5" in an Indian CTC field means lakh
    return [$num > 0 ? round($num, 2) : null, $currency, $period];
}

/** Indian cities → state, for location filters. */
const CITY_STATES = ['noida' => 'Uttar Pradesh', 'greater noida' => 'Uttar Pradesh', 'ghaziabad' => 'Uttar Pradesh', 'lucknow' => 'Uttar Pradesh', 'kanpur' => 'Uttar Pradesh', 'agra' => 'Uttar Pradesh',
    'meerut' => 'Uttar Pradesh', 'varanasi' => 'Uttar Pradesh', 'prayagraj' => 'Uttar Pradesh', 'delhi' => 'Delhi', 'new delhi' => 'Delhi', 'gurugram' => 'Haryana', 'gurgaon' => 'Haryana',
    'faridabad' => 'Haryana', 'mumbai' => 'Maharashtra', 'navi mumbai' => 'Maharashtra', 'thane' => 'Maharashtra', 'pune' => 'Maharashtra', 'nagpur' => 'Maharashtra',
    'bengaluru' => 'Karnataka', 'bangalore' => 'Karnataka', 'mysuru' => 'Karnataka', 'mysore' => 'Karnataka', 'hyderabad' => 'Telangana', 'chennai' => 'Tamil Nadu', 'coimbatore' => 'Tamil Nadu',
    'kolkata' => 'West Bengal', 'ahmedabad' => 'Gujarat', 'surat' => 'Gujarat', 'vadodara' => 'Gujarat', 'jaipur' => 'Rajasthan', 'chandigarh' => 'Chandigarh', 'mohali' => 'Punjab',
    'ludhiana' => 'Punjab', 'amritsar' => 'Punjab', 'jalandhar' => 'Punjab', 'indore' => 'Madhya Pradesh', 'bhopal' => 'Madhya Pradesh', 'kochi' => 'Kerala', 'thiruvananthapuram' => 'Kerala',
    'visakhapatnam' => 'Andhra Pradesh', 'patna' => 'Bihar', 'ranchi' => 'Jharkhand', 'bhubaneswar' => 'Odisha', 'dehradun' => 'Uttarakhand', 'guwahati' => 'Assam'];

/** "Sector 62, Noida, UP" → ['Noida', 'Uttar Pradesh', 'India']. */
function location_split(?string $loc): array
{
    $l = strtolower(trim((string) $loc));
    if ($l === '') return [null, null, null];
    foreach (CITY_STATES as $city => $state) if (preg_match('/\b' . preg_quote($city, '/') . '\b/', $l)) return [ucwords($city), $state, 'India'];
    foreach (INDIAN_STATES as $st) if (str_contains($l, strtolower($st))) return [null, $st, 'India'];
    return [mb_convert_case(trim(explode(',', $loc)[0]), MB_CASE_TITLE, 'UTF-8'), null, null];
}

/** Numeric years from "4.5 years", "4 yrs 6 months", "4". */
function exp_years(?string $t): ?float
{
    if (!preg_match('/(\d+(?:\.\d+)?)/', (string) $t, $m)) return null;
    $y = (float) $m[1];
    if (preg_match('/(\d+)\s*(?:months?|mos?)/i', (string) $t, $mm) && preg_match('/(\d+)\s*(?:years?|yrs?)/i', (string) $t)) $y += (int) $mm[1] / 12;
    return $y <= 50 ? round($y, 1) : null;
}

/** Profile completeness in % and what is missing (Candidates dashboard data-quality indicator). */
function cand_completeness(array $c): array
{
    $checks = ['name' => 'Name', 'email' => 'E-mail', 'phone' => 'Phone', 'designation' => 'Designation', 'current_employer' => 'Employer', 'work_exp' => 'Experience',
        'key_skills' => 'Skills', 'education' => 'Education', 'current_location' => 'Location', 'expected_ctc' => 'Expected CTC', 'notice_period' => 'Notice period', 'resume' => 'Resume'];
    $missing = [];
    foreach ($checks as $k => $l) if (trim((string) ($c[$k] ?? '')) === '') $missing[] = $l;
    return [(int) round((count($checks) - count($missing)) / count($checks) * 100), $missing];
}

/* ───────────────────────── Duplicate service ───────────────────────── */

/** Serialises every candidate creation / identity change (released automatically if the request dies). */
function cand_lock(): void
{
    if ((int) val("SELECT GET_LOCK('hrms_cand_identity', 10)") !== 1) throw new RuntimeException('The candidate database is busy. Please try again in a moment.');
}

function cand_unlock(): void
{
    val("SELECT RELEASE_LOCK('hrms_cand_identity')");
}

/**
 * Existing master candidates matching the e-mail OR the phone. Returns
 *   ['matches' => [id => row + 'by' => 'e-mail'|'phone'|'e-mail and phone'], 'conflict' => bool, 'reason' => text]
 * conflict = the e-mail matches one record and the phone another (needs an administrator).
 */
function cand_find_matches(?string $email, ?string $phoneE164, array $excludeIds = []): array
{
    $rows = [];
    $ex = array_map('intval', array_filter($excludeIds)) ?: [0];
    $exSql = implode(',', $ex);
    $base = "SELECT c.id, c.name, c.email, c.phone, c.stage, c.created_at, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id
             WHERE c.master_id IS NULL AND c.record_status <> 'Deleted' AND c.id NOT IN ($exSql) AND ";
    if ($email !== null) {
        $ids = array_column(all('SELECT candidate_id FROM hrm_candidate_identities WHERE kind = ? AND value = ?', ['email', $email]), 'candidate_id');
        foreach (all($base . '(c.email_norm = ? OR (c.email_norm IS NULL AND LOWER(TRIM(c.email)) = ?)' . ($ids ? ' OR c.id IN (' . implode(',', array_map('intval', $ids)) . ')' : '') . ') LIMIT 20', [$email, $email]) as $r) {
            $rows[(int) $r['id']] = $r + ['by' => 'e-mail'];
        }
    }
    if ($phoneE164 !== null) {
        $ids = array_column(all('SELECT candidate_id FROM hrm_candidate_identities WHERE kind = ? AND value = ?', ['phone', $phoneE164]), 'candidate_id');
        $legacy = str_starts_with($phoneE164, '+91') ? ' OR (c.phone_norm IS NULL AND ' . phone_key_sql('c.phone') . ' = ' . db()->quote(substr($phoneE164, 3)) . ')' : '';
        foreach (all($base . '(c.phone_norm = ?' . $legacy . ($ids ? ' OR c.id IN (' . implode(',', array_map('intval', $ids)) . ')' : '') . ') LIMIT 20', [$phoneE164]) as $r) {
            $id = (int) $r['id'];
            $rows[$id] = isset($rows[$id]) ? ['by' => 'e-mail and phone'] + $rows[$id] : $r + ['by' => 'phone'];
        }
    }
    $byEmail = array_keys(array_filter($rows, fn($r) => str_contains($r['by'], 'e-mail')));
    $byPhone = array_keys(array_filter($rows, fn($r) => str_contains($r['by'], 'phone')));
    $conflict = $byEmail && $byPhone && !array_intersect($byEmail, $byPhone);
    $reason = !$rows ? '' : ($conflict ? 'E-mail matches #' . implode(', #', $byEmail) . ' and phone matches a different record #' . implode(', #', $byPhone)
        : implode('; ', array_map(fn($r) => 'Same ' . $r['by'] . ' as #' . $r['id'], $rows)));
    return ['matches' => $rows, 'conflict' => $conflict, 'reason' => $reason];
}

/** Registers a master's identifiers. Returns false when another record already owns one of them (race / existing duplicate). */
function cand_register(int $id, ?string $email, ?string $phone): bool
{
    $ok = true;
    q('DELETE FROM hrm_candidate_identities WHERE candidate_id = ?', [$id]); // identifiers that changed are released
    foreach (['email' => $email, 'phone' => $phone] as $kind => $v) {
        if ($v === null) continue;
        try {
            insert('hrm_candidate_identities', ['kind' => $kind, 'value' => $v, 'candidate_id' => $id]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) != 1062) throw $e;
            $ok = false;
        }
    }
    return $ok;
}

/** Opens a duplicate review (pending data is kept so nothing the user typed is lost). */
function dup_review_open(array $match, string $source, ?int $candidateId = null, ?array $payload = null): int
{
    $id = insert('hrm_candidate_dup_reviews', ['candidate_id' => $candidateId, 'matched_ids' => implode(',', array_keys($match['matches'])), 'match_reason' => mb_substr($match['reason'] ?: 'Potential duplicate', 0, 255),
        'source' => $source, 'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, 'requested_by' => user()['id'] ?? null]);
    if ($candidateId) q("UPDATE hrm_candidates SET dup_status = 'flagged' WHERE id = ?", [$candidateId]);
    log_activity('duplicate_flagged', 'candidate', $candidateId, "Review #$id · $source · " . mb_substr($match['reason'], 0, 150));
    return $id;
}

/**
 * Saves identity + derived columns of one candidate row (e-mail / phone normalised, numeric salary, qualification level,
 * city / state) without overwriting values someone entered, registers a master's identifiers and syncs Resdex.
 */
function cand_after_write(int $id, string $source = 'system'): void
{
    $c = one('SELECT * FROM hrm_candidates WHERE id = ?', [$id]);
    if (!$c) return;
    $idn = cand_identity($c['email'], $c['phone']);
    $set = ['email_norm' => $idn['email'], 'phone_norm' => $idn['phone'], 'phone_country' => $idn['phone_country'], 'identity_status' => $idn['status']];
    [$cur, $curCcy, $curPer] = ctc_to_annual($c['current_ctc']);
    [$exp] = ctc_to_annual($c['expected_ctc']);
    if ($c['current_ctc_amount'] === null && $cur !== null) $set['current_ctc_amount'] = $cur;
    if ($c['expected_ctc_amount'] === null && $exp !== null) $set['expected_ctc_amount'] = $exp;
    if ($c['ctc_currency'] === null && ($cur !== null || $exp !== null)) $set['ctc_currency'] = $curCcy ?? 'INR';
    if ($c['salary_period'] === null && ($cur !== null || $exp !== null)) $set['salary_period'] = 'Annual';
    if ($c['qualification_level'] === null && ($lvl = qualification_level_of($c['education'] . ' ' . $c['degree']))) $set['qualification_level'] = $lvl;
    [$city, $state, $country] = location_split($c['current_location']);
    if ($c['city'] === null && $city) $set['city'] = mb_substr($city, 0, 80);
    if ($c['state'] === null && $state) $set['state'] = $state;
    if ($c['country'] === null && $country) $set['country'] = $country;
    if ($c['resume'] && !$c['resume_uploaded_at']) $set['resume_uploaded_at'] = $c['updated_at'] ?: $c['created_at'];
    q('UPDATE hrm_candidates SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $id]);
    if ($c['master_id'] === null && $c['record_status'] !== 'Deleted' && !cand_register($id, $idn['email'], $idn['phone']) && $c['dup_status'] !== 'override') {
        // Another master owns this e-mail / phone (an older duplicate, or a race between two creations): flag it for review.
        $m = cand_find_matches($idn['email'], $idn['phone'], [$id]);
        if ($m['matches'] && !val("SELECT id FROM hrm_candidate_dup_reviews WHERE candidate_id = ? AND status = 'Pending'", [$id])) dup_review_open($m, $source, $id);
    }
    rdx_sync($id);
}

/**
 * The master record an application of this person should be linked to (careers apply, apply link, vendor submission,
 * Resdex add-to-job …). Returns ['master_id' => id|null, 'conflict' => match|null]. Must run inside cand_lock().
 */
function cand_master_for(?string $email, ?string $phone): array
{
    $idn = cand_identity($email, $phone);
    if ($idn['email'] === null && $idn['phone'] === null) return ['master_id' => null, 'conflict' => null, 'identity' => $idn];
    $m = cand_find_matches($idn['email'], $idn['phone']);
    if (!$m['matches']) return ['master_id' => null, 'conflict' => null, 'identity' => $idn];
    if ($m['conflict']) return ['master_id' => null, 'conflict' => $m, 'identity' => $idn];
    // E-mail and phone point to the same person (or only one was supplied): link to the oldest matching master.
    $ids = array_keys($m['matches']);
    sort($ids);
    return ['master_id' => $ids[0], 'conflict' => null, 'identity' => $idn];
}

/**
 * Inserts a candidate application through the central service: links it to the person's master record when one exists,
 * opens a review when e-mail and phone point to different people, and syncs Resdex. Returns the new row id.
 * $data = hrm_candidates columns. $consent = consent source label ('Careers application', 'Partner attestation' …) or null.
 */
function cand_insert(array $data, string $source, ?string $consent = null): int
{
    cand_lock();
    try {
        $m = cand_master_for($data['email'] ?? null, $data['phone'] ?? null);
        if ($m['master_id']) $data['master_id'] = $m['master_id'];
        if ($consent) $data += ['consent_status' => 'Given', 'consent_at' => date('Y-m-d H:i:s'), 'consent_source' => mb_substr($consent, 0, 60)];
        $id = insert('hrm_candidates', $data);
        if ($m['conflict']) dup_review_open($m['conflict'], $source, $id, null);
        cand_after_write($id, $source);
    } finally {
        cand_unlock();
    }
    return $id;
}

/* ───────────────────────── Resdex index ───────────────────────── */

/** Why a candidate is (not) searchable in Resdex: null = eligible, otherwise the reason. */
function rdx_ineligible(array $c, ?array $talent = null): ?string
{
    if ($c['master_id'] !== null) return 'Application of master record #' . $c['master_id'];
    if ($c['record_status'] !== 'Active') return 'Record status: ' . $c['record_status'];
    if ((int) $c['resdex_opt_out'] === 1) return 'Excluded from database search';
    if ($c['consent_status'] === 'Withdrawn') return 'Consent withdrawn';
    if (setting('rdx_consent_mode', 'not_withdrawn') === 'given_only' && $c['consent_status'] !== 'Given') return 'No recorded consent';
    if ($talent && (int) $talent['open_to_work'] === 0) return 'Candidate hid their profile from employers';
    if ($c['retention_until'] && $c['retention_until'] < date('Y-m-d')) return 'Retention period ended';
    if ($c['dup_status'] === 'flagged') return 'Potential duplicate under review';
    if (trim((string) $c['name']) === '') return 'No name';
    return null;
}

/** Adds, refreshes or removes one candidate in the Resdex index. Never throws: failures are recorded for retry. */
function rdx_sync(int $id): bool
{
    try {
        $c = one('SELECT * FROM hrm_candidates WHERE id = ?', [$id]);
        if (!$c) { q('DELETE FROM hrm_resdex_index WHERE candidate_id = ?', [$id]); return true; }
        $t = $c['talent_id'] ? one('SELECT * FROM hrm_talent WHERE id = ?', [$c['talent_id']]) : null;
        $why = rdx_ineligible($c, $t);
        if ($why !== null) {
            q('DELETE FROM hrm_resdex_index WHERE candidate_id = ?', [$id]);
            q("UPDATE hrm_candidates SET index_status = 'excluded', indexed_at = NOW(), index_error = ? WHERE id = ?", [mb_substr($why, 0, 255), $id]);
            return true;
        }
        $row = ['candidate_id' => $id, 'talent_id' => $c['talent_id'], 'name' => mb_substr($c['name'], 0, 150), 'headline' => $t['headline'] ?? null,
            'designation' => $c['designation'] ?: ($t['designation'] ?? null), 'department' => $c['department'], 'employer' => $c['current_employer'] ?: ($t['current_employer'] ?? null),
            'skills' => $c['key_skills'] ?: ($t['key_skills'] ?? null), 'summary' => $t['summary'] ?? null,
            'exp_years' => exp_years($c['work_exp']) ?? (isset($t['total_exp']) ? (float) $t['total_exp'] : null), 'city' => $c['city'], 'state' => $c['state'], 'country' => $c['country'],
            'location' => $c['current_location'], 'qual_level' => $c['qualification_level'], 'education' => mb_substr(trim(implode(', ', array_filter([$c['education'], $c['degree'], $c['institution']]))), 0, 255) ?: null,
            'industry_id' => $c['industry_id'], 'ctc_current' => $c['current_ctc_amount'], 'ctc_expected' => $c['expected_ctc_amount'], 'currency' => $c['ctc_currency'],
            'notice_period' => $c['notice_period'], 'source' => $c['source'], 'has_resume' => ($c['resume'] || !empty($t['resume'])) ? 1 : 0,
            'active_at' => max($c['updated_at'] ?: $c['created_at'], $t['updated_at'] ?? '0'), 'indexed_at' => date('Y-m-d H:i:s')];
        $cols = array_keys($row);
        q('REPLACE INTO hrm_resdex_index (' . implode(',', array_map(fn($k) => "`$k`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
        q("UPDATE hrm_candidates SET index_status = 'indexed', indexed_at = NOW(), index_error = NULL WHERE id = ?", [$id]);
        return true;
    } catch (Throwable $e) {
        error_log("Resdex sync #$id: " . $e->getMessage());
        try { q("UPDATE hrm_candidates SET index_status = 'failed', index_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $id]); } catch (Throwable $e2) {}
        return false;
    }
}

/**
 * Background work (page loads every 2 minutes while there is a backlog, otherwise every 30; and app/cron.php):
 * identity + derived columns for records written before v21 or outside the service, index retries.
 */
function cand_tick(bool $force = false, int $batch = 2000): int
{
    $backlog = (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE index_status IS NULL OR index_status IN ('pending','failed')");
    if (!$force) {
        if (time() - (int) setting('cand_tick_at', 0) < ($backlog ? 120 : 1800)) return 0;
        set_setting('cand_tick_at', time());
    }
    if (!$backlog) return 0;
    $n = 0;
    cand_lock();
    try {
        foreach (array_column(all("SELECT id FROM hrm_candidates WHERE index_status IS NULL OR index_status IN ('pending','failed') ORDER BY id LIMIT " . (int) $batch), 'id') as $id) {
            cand_after_write((int) $id, 'backfill');
            $n++;
        }
    } finally {
        cand_unlock();
    }
    return $n;
}

function cand_db_counts(): array
{
    if (!table_exists('hrm_candidate_dup_reviews')) return ['dup_reviews' => 0];
    return ['dup_reviews' => can('manage_candidate_db') ? (int) val("SELECT COUNT(*) FROM hrm_candidate_dup_reviews WHERE status = 'Pending'") : 0];
}

/* ───────────────────────── Careers-portal profiles without an application (optional data update) ───────────────────────── */

function talent_without_master_rows(): array
{
    return all('SELECT t.* FROM hrm_talent t WHERE NOT EXISTS (SELECT 1 FROM hrm_candidates c WHERE c.talent_id = t.id) ORDER BY t.id');
}

/** Creates the master candidate record for a careers-portal profile (or links it to the existing person). */
function talent_ensure_master(array $t): ?int
{
    if (trim((string) $t['full_name']) === '' || ($t['email'] === null && $t['phone'] === null)) return null;
    if ($id = val('SELECT id FROM hrm_candidates WHERE talent_id = ? AND master_id IS NULL ORDER BY id LIMIT 1', [$t['id']])) return (int) $id;
    return cand_insert(talent_to_candidate($t) + ['job_id' => null, 'source' => 'Careers portal', 'stage' => 'Applied'], 'careers profile', 'Careers portal profile');
}

/* ───────────────────────── Repeat applications of one person (optional data update) ───────────────────────── */

/** [laterId => masterId] for records whose e-mail and phone agree with an earlier master (one may be blank). */
function link_application_rows(): array
{
    $seenE = $seenP = $ident = $links = [];
    foreach (all("SELECT id, email, phone FROM hrm_candidates WHERE master_id IS NULL AND record_status <> 'Deleted' ORDER BY id") as $r) {
        $i = cand_identity($r['email'], $r['phone']);
        $id = (int) $r['id'];
        $first = $i['email'] !== null && isset($seenE[$i['email']]) ? $seenE[$i['email']] : ($i['phone'] !== null && isset($seenP[$i['phone']]) ? $seenP[$i['phone']] : null);
        if ($first !== null) {
            [$fe, $fp] = $ident[$first];
            $compatible = ($i['email'] === null || $fe === null || $i['email'] === $fe) && ($i['phone'] === null || $fp === null || $i['phone'] === $fp);
            if ($compatible) { $links[$id] = $first; continue; }
        }
        $ident[$id] = [$i['email'], $i['phone']];
        if ($i['email'] !== null) $seenE[$i['email']] ??= $id;
        if ($i['phone'] !== null) $seenP[$i['phone']] ??= $id;
    }
    return $links;
}

function link_applications_run(): int
{
    $n = 0;
    cand_lock();
    try {
        foreach (link_application_rows() as $id => $master) {
            q("UPDATE hrm_candidates SET master_id = ?, dup_status = NULL WHERE id = ? AND master_id IS NULL", [$master, $id]);
            q('DELETE FROM hrm_candidate_identities WHERE candidate_id = ?', [$id]);
            q("UPDATE hrm_candidate_dup_reviews SET status = 'Resolved', resolution = 'linked', resolution_reason = 'Linked as a repeat application (data update)', resolved_by = ?, resolved_at = NOW() WHERE candidate_id = ? AND status = 'Pending'", [user()['id'] ?? null, $id]);
            cand_after_write($master, 'link');
            rdx_sync($id);
            $n++;
        }
    } finally {
        cand_unlock();
    }
    return $n;
}
