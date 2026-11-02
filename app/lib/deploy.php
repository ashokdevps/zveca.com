<?php
/**
 * Safe deployments: the live data is never changed by a code release.
 *
 *   1. Schema upgrades (ensure_schema) are additive only: new tables, new columns, new indexes, new settings rows.
 *      They never update or delete an existing value.
 *   2. Anything that would change existing rows (e.g. granting new modules to logins with a custom module list) is an
 *      optional data update: listed under Administration → Database & Updates, applied only when a Super Admin clicks it.
 *   3. Before any schema upgrade (and before any optional update) the whole database is backed up to app/storage/backups.
 *   4. A data fingerprint (rows + content hash of every table) can be taken before a deploy and verified after it.
 */

const BACKUP_DIR = STORAGE_DIR . '/backups';
const BACKUP_KEEP = 15;

/* ───────────────────────── One upgrade at a time ───────────────────────── */

/** MySQL named lock, so two simultaneous first requests after a deploy never upgrade the schema twice. */
function schema_lock(int $wait = 60): bool
{
    try {
        return (int) val('SELECT GET_LOCK(?, ?)', ['hrms_schema_' . (string) val('SELECT DATABASE()'), $wait]) === 1;
    } catch (Throwable $e) {
        return true; // locking unsupported: carry on (every step is idempotent)
    }
}

function schema_unlock(): void
{
    try { q('SELECT RELEASE_LOCK(?)', ['hrms_schema_' . (string) val('SELECT DATABASE()')]); } catch (Throwable $e) {}
}

/* ───────────────────────── Backups ───────────────────────── */

/**
 * Full logical backup (structure + data of every table) to app/storage/backups/<db>-<date>-<label>.sql.gz.
 * Streams row by row, so memory stays flat on large tables. Returns the file path; throws on failure.
 * Restore with phpMyAdmin (Import) or: gunzip -c file.sql.gz | mysql -u USER -p DATABASE
 */
function db_backup(string $label = 'manual'): string
{
    if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0750, true)) throw new RuntimeException('Cannot create ' . BACKUP_DIR);
    @set_time_limit(600);
    $pdo = db();
    $dbName = (string) val('SELECT DATABASE()');
    $file = BACKUP_DIR . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $dbName) . '-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($label)) . '.sql.gz';
    $gz = gzopen($file . '.part', 'wb6');
    if (!$gz) throw new RuntimeException('Cannot write ' . $file);
    gzwrite($gz, "-- Adorable HRMS backup of `$dbName`, " . date('c') . " ($label)\n-- Schema version " . (setting('schema_version') ?: '0') . ', app build ' . APP_BUILD . "\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
    $tables = array_column(all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"), 'Tables_in_' . $dbName);
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    try {
        foreach ($tables as $t) {
            $create = one('SHOW CREATE TABLE ' . qi($t))['Create Table'];
            gzwrite($gz, "DROP TABLE IF EXISTS " . qi($t) . ";\n$create;\n");
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); // stream rows
            $st = $pdo->query('SELECT * FROM ' . qi($t));
            $batch = [];
            $cols = null;
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols ??= '(' . implode(',', array_map('qi', array_keys($r))) . ')';
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)) . ')';
                if (count($batch) === 200) { gzwrite($gz, 'INSERT INTO ' . qi($t) . " $cols VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
            }
            if ($batch) gzwrite($gz, 'INSERT INTO ' . qi($t) . " $cols VALUES\n" . implode(",\n", $batch) . ";\n");
            $st->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- end of backup\n");
    } catch (Throwable $e) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        gzclose($gz);
        @unlink($file . '.part');
        throw $e;
    }
    gzclose($gz);
    rename($file . '.part', $file);
    backups_rotate();
    return $file;
}

/** Backups, newest first: [name, size, time]. */
function backups_list(): array
{
    $out = [];
    foreach (glob(BACKUP_DIR . '/*.sql.gz') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** Keeps the newest BACKUP_KEEP backups. */
function backups_rotate(): void
{
    foreach (array_slice(backups_list(), BACKUP_KEEP) as $b) @unlink(BACKUP_DIR . '/' . $b['name']);
}

/* ───────────────────────── Data fingerprint ───────────────────────── */

/** Settings the application itself maintains (version bookkeeping, timers); not business data. */
const FINGERPRINT_IGNORE_SETTINGS = ['schema_version', 'data_updates_offered', 'eng_tick_at', 'lock_tick_at', 'cand_tick_at', 'rdx_tick_at', 'doc_tick_at', 'hrd_tick_at', 'emp_code_seq', 'insights_tick_at', 'visit_salt'];

/**
 * Fingerprint of the existing data: per table its columns, the rows that existed (scope) and a content hash
 * (SUM of the first 60 bits of MD5(row): order-independent, any changed value changes it).
 * Scope: tables with an `id` column → rows with id <= the highest id at snapshot time (new rows added later are
 * ignored, edits / deletions of existing rows are detected); hrm_settings → the keys that existed; others → all rows.
 * $snap: a previous fingerprint's tables (verify mode: same columns and scope).
 */
function data_fingerprint(?array $snap = null): array
{
    $dbName = (string) val('SELECT DATABASE()');
    $tables = $snap ? array_keys($snap) : array_column(all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"), 'Tables_in_' . $dbName);
    $skip = ['hrm_login_attempts', 'hrm_notification_log', 'hrm_schema_log', 'hrm_visits']; // throttling / reminder / upgrade bookkeeping; anonymous page views (expire after visit_retention_days)
    $out = [];
    foreach ($tables as $t) {
        if (in_array($t, $skip, true)) continue;
        if (!table_exists($t)) { $out[$t] = ['missing' => true]; continue; }
        $have = table_columns($t);
        $cols = $snap ? ($snap[$t]['columns'] ?? []) : $have;
        $missing = array_values(array_diff($cols, $have));
        $cols = array_values(array_intersect($cols, $have));
        $where = '1=1';
        $params = [];
        $scope = $snap[$t]['scope'] ?? null;
        if (!$snap) {
            if ($t === 'hrm_settings') {
                $scope = ['keys' => array_values(array_diff(array_column(all('SELECT k FROM hrm_settings'), 'k'), FINGERPRINT_IGNORE_SETTINGS))];
            } elseif (in_array('id', $have, true)) {
                $scope = ['max_id' => (int) val('SELECT COALESCE(MAX(id), 0) FROM ' . qi($t))];
            }
        }
        if (isset($scope['max_id'])) { $where = 'id <= ?'; $params[] = $scope['max_id']; }
        if (isset($scope['keys'])) {
            $where = $scope['keys'] ? 'k IN (' . implode(',', array_fill(0, count($scope['keys']), '?')) . ')' : '0=1';
            $params = $scope['keys'];
        }
        if (!$cols) { $out[$t] = ['columns' => [], 'rows' => 0, 'hash' => '0', 'scope' => $scope, 'missing_columns' => $missing]; continue; }
        $expr = 'CONCAT_WS(CHAR(31),' . implode(',', array_map(fn($c) => 'COALESCE(CAST(' . qi($c) . ' AS CHAR), CHAR(0))', $cols)) . ')';
        $r = one('SELECT COUNT(*) n, COALESCE(SUM(CAST(CONV(SUBSTRING(MD5(' . $expr . '), 1, 15), 16, 10) AS UNSIGNED)), 0) h FROM ' . qi($t) . " WHERE $where", $params);
        $out[$t] = ['columns' => $cols, 'rows' => (int) $r['n'], 'hash' => (string) $r['h'], 'scope' => $scope] + ($missing ? ['missing_columns' => $missing] : []);
    }
    return $out;
}
/** Saves a fingerprint to app/storage/backups/fingerprint-<date>-<label>.json and returns the path. */
function fingerprint_save(string $label = 'manual'): string
{
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0750, true);
    $data = ['taken_at' => date('c'), 'schema_version' => (int) setting('schema_version', 0), 'build' => APP_BUILD, 'label' => $label, 'tables' => data_fingerprint()];
    $file = BACKUP_DIR . '/fingerprint-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($label)) . '.json';
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    return $file;
}

/**
 * Compares the current data with a saved fingerprint. Returns [changed tables => details, summary].
 * Tables created after the fingerprint are ignored (they hold no pre-existing data).
 */
function fingerprint_verify(string $file): array
{
    $snap = json_decode((string) file_get_contents($file), true);
    if (!$snap || empty($snap['tables'])) throw new RuntimeException('Not a valid fingerprint file.');
    $only = array_filter($snap['tables'], fn($t) => empty($t['missing']));
    $now = data_fingerprint($only);
    $changed = [];
    foreach ($only as $t => $cols) {
        $a = $snap['tables'][$t];
        $b = $now[$t] ?? ['missing' => true];
        if (!empty($b['missing'])) { $changed[$t] = 'table no longer exists'; continue; }
        if (!empty($b['missing_columns'])) { $changed[$t] = 'columns removed: ' . implode(', ', $b['missing_columns']); continue; }
        if ($a['rows'] !== $b['rows'] || $a['hash'] !== $b['hash']) {
            $changed[$t] = $a['rows'] === $b['rows'] ? 'existing values changed (' . $a['rows'] . ' rows checked)' : 'existing rows ' . $a['rows'] . ' → ' . $b['rows'] . ($b['rows'] < $a['rows'] ? ' (rows deleted)' : '');
        }
    }
    return [$changed, ['tables' => count($only), 'taken_at' => $snap['taken_at'], 'label' => $snap['label'] ?? '', 'schema_from' => $snap['schema_version'] ?? null]];
}

function fingerprints_list(): array
{
    $out = glob(BACKUP_DIR . '/fingerprint-*.json') ?: [];
    rsort($out);
    return array_map('basename', $out);
}

/* ───────────────────────── Optional data updates ───────────────────────── */

/**
 * Changes to existing rows that older versions applied automatically during an upgrade. They are now offered, never
 * forced: key => [version that introduced it, title, explanation, count() affected rows, run() → rows changed].
 */
function data_updates(): array
{
    // Logins with a custom module list that have $needs (all) but lack $add.
    $permRows = function (array $needs, string $add, bool $staffOnly = true, ?string $role = null) {
        $rows = [];
        foreach (all("SELECT id, usertype, permissions FROM it_login WHERE permissions IS NOT NULL AND permissions <> ''") as $r) {
            $list = json_decode((string) $r['permissions'], true);
            $ur = normalize_role($r['usertype']);
            if (!is_array($list) || in_array($add, $list, true)) continue;
            if ($role !== null && $ur !== $role) continue;
            if ($role === null && $staffOnly && in_array($ur, EXTERNAL_ROLES, true)) continue;
            if (array_diff($needs, $list)) continue;
            $rows[(int) $r['id']] = $list;
        }
        return $rows;
    };
    $grant = function (array $rows, string $add): int {
        foreach ($rows as $id => $list) q('UPDATE it_login SET permissions = ? WHERE id = ?', [json_encode(array_values(array_unique([...$list, $add]))), $id]);
        return count($rows);
    };
    $perm = fn(int $v, string $title, string $why, array $needs, string $add, bool $staffOnly = true, ?string $role = null) => [
        'version' => $v, 'title' => $title, 'why' => $why,
        'count' => fn() => count($permRows($needs, $add, $staffOnly, $role)), 'run' => fn() => $grant($permRows($needs, $add, $staffOnly, $role), $add)];
    return [
        'perm_tickets' => $perm(7, 'Give help & AI to logins with a custom module list', 'Adds "Help & support tickets" and "AI assistant" to logins whose modules were customised before these existed.', [], 'raise_tickets', false),
        'perm_performance' => $perm(10, 'Give "My performance" to employees with a custom module list', 'Adds self-service performance to custom logins that already have "My leaves".', ['self_leaves'], 'self_performance'),
        'perm_policies' => $perm(14, 'Give "HR policies" to staff with a custom module list', 'Lets every staff login read and acknowledge HR policies.', [], 'view_policies'),
        'perm_onboarding' => $perm(16, 'Give "Onboarding" to HR logins with a custom module list', 'Adds the Onboarding module to custom logins that have both Recruitment and Manage employees.', ['manage_recruitment', 'manage_employees'], 'manage_onboarding'),
        'perm_engagements' => $perm(17, 'Give "Client services" to HR logins with a custom module list', 'Adds Client Requests to custom logins that have Recruitment and Onboarding.', ['manage_recruitment', 'manage_onboarding'], 'manage_engagements'),
        'perm_client_services' => $perm(17, 'Give "Services & agreements" to client logins with a custom module list', 'Lets existing client logins with customised modules request services and see the Document Centre.', [], 'client_services', false, 'client'),
        'perm_vendor_scores' => $perm(20, 'Give "Vendor performance scores" to logins that manage sub-vendors', 'Lets custom logins with the Sub-vendors module add and edit industry-wise vendor scores and industries.', ['manage_vendors'], 'manage_vendor_scores'),
        'perm_candidate_locks' => $perm(20, 'Give "Candidate ownership" to logins that manage sub-vendors and recruitment', 'Lets custom logins with Sub-vendors and Recruitment review ownership conflicts and extend or release candidate locks.', ['manage_vendors', 'manage_recruitment'], 'manage_candidate_locks'),
        'lock_backfill' => ['version' => 20, 'title' => 'Protect recent vendor submissions with an ownership lock',
            'why' => 'Creates a company-specific lock (from the submission date, for the configured lock period) for each vendor submission still within that period and not rejected, so ownership rules also cover profiles sent before this release. Existing candidate records are not changed; only new lock records are added.',
            'count' => fn() => count(lock_backfill_rows()), 'run' => fn() => lock_backfill()],
        'offer_letters_to_clients' => ['version' => 22, 'title' => 'Show offer letters already sent to candidates in their client\'s Document Centre',
            'why' => 'Offers sent before this release are recorded once (their current approved text) for the client of the candidate\'s job, so clients with the HR / recruitment entitlement see them under Offer letters. Onboarding records are not changed.',
            'count' => fn() => count(offer_backfill_rows()), 'run' => function () { $n = 0; foreach (offer_backfill_rows() as $id) { offer_share_sync_force($id); $n++; } return $n; }],
        'perm_hr_documents' => $perm(24, 'Give "HR Document Centre" to HR logins with a custom module list', 'Adds the HR Document Centre (templates, prepared documents, signatures) to custom logins that can manage onboarding.', ['manage_onboarding'], 'manage_hr_documents'),
        'perm_induction' => $perm(24, 'Give "Induction & policies" to HR logins with a custom module list', 'Lets custom logins that can manage onboarding assign induction material and track acknowledgements.', ['manage_onboarding'], 'manage_induction'),
        'perm_candidate_db' => $perm(21, 'Give "Candidate database" to HR logins with a custom module list', 'Lets custom logins with Recruitment resolve duplicate candidates and handle consent / deletion requests.', ['manage_recruitment', 'manage_employees'], 'manage_candidate_db'),
        'talent_masters' => ['version' => 21, 'title' => 'Add careers-portal profiles without an application to the central candidate database',
            'why' => 'Candidates who created a careers-portal profile but never applied are only in the talent pool. This adds one candidate record for each (linked to the profile, consent from the portal sign-up) so they appear in the Candidates dashboard and stay searchable in Resdex. Existing records are not changed.',
            'count' => fn() => count(talent_without_master_rows()), 'run' => function () { $n = 0; foreach (talent_without_master_rows() as $t) if (talent_ensure_master($t)) $n++; return $n; }],
        'link_applications' => ['version' => 21, 'title' => 'Link repeat applications of the same person to one candidate record',
            'why' => 'Before v21 the same person applying to two jobs got two independent records. This links each later record whose e-mail and phone both match (or one matches and the other is blank) to the first one, so the person is counted once and appears once in Resdex. No record is deleted or merged; conflicting matches are left for the duplicate review queue.',
            'count' => fn() => count(link_application_rows()), 'run' => fn() => link_applications_run()],
        'client_signatories' => ['version' => 17, 'title' => 'Mark existing client logins as authorised signatories',
            'why' => 'Only signatories can accept proposals and e-sign agreements. Existing client logins start as non-signatories; apply this to make all of them signatories, or set them one by one in Users & Roles.',
            'count' => fn() => (int) val("SELECT COUNT(*) FROM it_login WHERE LOWER(usertype) = 'client' AND is_signatory = 0"),
            'run' => fn() => q("UPDATE it_login SET is_signatory = 1 WHERE LOWER(usertype) = 'client' AND is_signatory = 0")->rowCount()],
        'stage_screening' => ['version' => 9, 'title' => 'Rename the old "Screening" stage to "HR Screening"', 'why' => 'Candidates still in the retired "Screening" stage do not show on the pipeline board.',
            'count' => fn() => (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE stage = 'Screening'"),
            'run' => fn() => q("UPDATE hrm_candidates SET stage = 'HR Screening' WHERE stage = 'Screening'")->rowCount()],
        'jobs_published_at' => ['version' => 9, 'title' => 'Set a published date on open jobs that have none', 'why' => 'Used for "posted N days ago" on the careers page; the creation date is used.',
            'count' => fn() => (int) val("SELECT COUNT(*) FROM hrm_jobs WHERE published_at IS NULL AND status = 'Open'"),
            'run' => fn() => q("UPDATE hrm_jobs SET published_at = created_at WHERE published_at IS NULL AND status = 'Open'")->rowCount()],
        'invoice_items' => ['version' => 13, 'title' => 'Create line items for invoices issued before line items existed', 'why' => 'Each old invoice gets one line from its amount (and its notes as the description). The invoice totals are not changed.',
            'count' => fn() => (int) val('SELECT COUNT(*) FROM hrm_invoices i WHERE NOT EXISTS (SELECT 1 FROM hrm_invoice_items t WHERE t.invoice_id = i.id)'),
            'run' => function () {
                $n = 0;
                foreach (all('SELECT i.id, i.amount, i.notes FROM hrm_invoices i WHERE NOT EXISTS (SELECT 1 FROM hrm_invoice_items t WHERE t.invoice_id = i.id)') as $i) {
                    $desc = trim((string) $i['notes']);
                    insert('hrm_invoice_items', ['invoice_id' => $i['id'], 'description' => mb_substr($desc !== '' ? $desc : 'Professional services', 0, 500), 'sac' => null, 'qty' => 1, 'rate' => $i['amount'], 'amount' => $i['amount'], 'sort' => 0]);
                    $n++;
                }
                return $n; // the invoice's own notes are kept as they are
            }],
        'public_resumes' => ['version' => 9, 'title' => 'Move resumes from the old public apply forms into private storage', 'why' => 'Copies resumes uploaded by the retired apply-now / job-application pages into protected storage and points the candidates at the copies.',
            'count' => fn() => (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE resume LIKE 'http%' OR resume LIKE '%job-application%' OR resume LIKE '%apply-now%'"),
            'run' => function () { $before = (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE resume LIKE 'http%' OR resume LIKE '%job-application%' OR resume LIKE '%apply-now%'"); migrate_public_resumes();
                return $before - (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE resume LIKE 'http%' OR resume LIKE '%job-application%' OR resume LIKE '%apply-now%'"); }],
    ];
}

/** Updates offered to this installation (the ones introduced after the version it upgraded from), not yet applied or dismissed. */
function data_updates_offered(): array
{
    $offered = json_decode((string) setting('data_updates_offered', '[]'), true) ?: [];
    return array_values(array_intersect($offered, array_keys(data_updates())));
}

/** Remembers which optional updates apply after an upgrade from version $from. */
function data_updates_offer(int $from): void
{
    $keys = array_keys(array_filter(data_updates(), fn($u) => $u['version'] > $from));
    set_setting('data_updates_offered', json_encode(array_values(array_unique(array_merge(data_updates_offered(), $keys)))));
    if ($keys && function_exists('notify')) notify(array_map('intval', array_column(all("SELECT id FROM it_login WHERE LOWER(usertype) = 'admin' AND status = 'active'"), 'id')), 'system', 'Upgrade complete: optional data updates to review', count($keys) . ' optional update(s) are waiting under Database & Updates. Nothing changes until you apply them.', url('maintenance'), 'ri-database-2-line', 'warning', 'du:' . $from . ':' . SCHEMA_VERSION);
}

function data_update_resolve(string $key): void
{
    set_setting('data_updates_offered', json_encode(array_values(array_diff(data_updates_offered(), [$key]))));
}

/** Upgrade history (newest first). */
function schema_log(): array
{
    return table_exists('hrm_schema_log') ? all('SELECT * FROM hrm_schema_log ORDER BY id DESC LIMIT 30') : [];
}
