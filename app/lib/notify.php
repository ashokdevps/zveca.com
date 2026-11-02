<?php
/**
 * Notification centre (schema v25).
 *   Needs action  live to-dos computed from pending_counts() (leave approvals, offers, tickets…): action_items()
 *   Updates       per-login event feed in hrm_notifications (read / unread), written by notify() / notify_perm()
 * The bell shows exactly what it lists: the action items' total plus unread updates (no hidden counters, no double counting).
 * dashboard/assets/js/notifications.js refreshes the bell every notif_poll_seconds via /dashboard/notifications/poll.
 */

const NOTIF_TONES = ['info', 'success', 'warning', 'danger'];

/**
 * Adds one notification per active login (deduplicated by $key per login: the same event never notifies twice).
 * Returns how many were created. Never throws: a notification must not break the action that caused it.
 */
function notify(array|int $loginIds, string $type, string $title, ?string $body = null, ?string $url = null, string $icon = 'ri-notification-3-line', string $tone = 'info', ?string $key = null): int
{
    $n = 0;
    try {
        if (!table_exists('hrm_notifications')) return 0;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $loginIds))));
        if (!$ids) return 0;
        $active = array_map('intval', array_column(all('SELECT id FROM it_login WHERE status = \'active\' AND id IN (' . implode(',', $ids) . ')'), 'id'));
        foreach ($active as $lid) {
            $st = db()->prepare('INSERT IGNORE INTO hrm_notifications (login_id, type, title, body, url, icon, tone, k) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$lid, mb_substr($type, 0, 30), mb_substr($title, 0, 190), $body !== null ? mb_substr($body, 0, 500) : null, $url !== null ? mb_substr($url, 0, 255) : null,
                mb_substr($icon, 0, 40), in_array($tone, NOTIF_TONES, true) ? $tone : 'info', $key !== null ? mb_substr($key, 0, 120) : null]);
            $n += $st->rowCount();
        }
    } catch (Throwable $e) {
        error_log('notify: ' . $e->getMessage());
    }
    return $n;
}

/**
 * Active internal logins holding any of the permissions (Super Admins included unless $admins = false).
 * $exclude: a login that should not be told about its own action.
 */
function logins_with(array|string $perms, bool $admins = true, ?int $exclude = null): array
{
    $perms = (array) $perms;
    $out = [];
    foreach (all("SELECT * FROM it_login WHERE status = 'active'") as $l) {
        $role = normalize_role($l['usertype']);
        if (in_array($role, EXTERNAL_ROLES, true)) continue;
        if ($role === 'admin' ? $admins : array_intersect($perms, permissions_for($l))) $out[] = (int) $l['id'];
    }
    return array_values(array_diff($out, $exclude ? [$exclude] : []));
}

/** notify() everyone with one of the permissions, except the person who caused the event. */
function notify_perm(array|string $perms, string $type, string $title, ?string $body = null, ?string $url = null, string $icon = 'ri-notification-3-line', string $tone = 'info', ?string $key = null, bool $admins = true): int
{
    return notify(logins_with($perms, $admins, user()['id'] ?? null), $type, $title, $body, $url, $icon, $tone, $key);
}

function notif_unread(int $loginId): int
{
    return table_exists('hrm_notifications') ? (int) val('SELECT COUNT(*) FROM hrm_notifications WHERE login_id = ? AND read_at IS NULL', [$loginId]) : 0;
}

function notif_feed(int $loginId, int $limit = 8, int $afterId = 0): array
{
    if (!table_exists('hrm_notifications')) return [];
    return all('SELECT * FROM hrm_notifications WHERE login_id = ? AND id > ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), [$loginId, $afterId]);
}

/**
 * The "Needs action" list for the signed-in user: [key, count, text, url, icon, tone]. Every counter that adds to the
 * bell must appear here, so the badge always equals what the menu shows.
 */
function action_items(): array
{
    if (!user()) return [];
    $c = pending_counts();
    $plural = fn(int $n, string $one, string $many) => $n === 1 ? $one : $many;
    $items = [
        ['pending_leaves', 'leave request(s) awaiting your approval', url('leaves', ['status' => 'Pending']), 'ri-calendar-check-line', 'info'],
        ['pending_expenses', 'expense claim(s) awaiting your approval', url('expenses', ['status' => 'Pending']), 'ri-bill-line', 'warning'],
        ['support_queue', 'ticket(s) in your support queue', url('support', ['a' => 'queue']), 'ri-customer-service-2-line', 'info'],
        ['my_ticket_updates', 'ticket(s) need your reply or rating', url('support'), 'ri-coupon-3-line', 'warning'],
        ['probation_due', 'probation review(s) due', url('performance', ['a' => 'probation']), 'ri-hourglass-2-line', 'danger'],
        ['my_reviews', 'performance review(s) need your input', url('performance', ['a' => can('manage_performance') ? null : 'my']), 'ri-star-smile-line', 'warning'],
        ['onboarding_approve', 'offer letter(s) awaiting your approval', url('onboarding', ['status' => 'Pending Approval']), 'ri-file-text-line', 'warning'],
        ['onboarding_review', 'onboarding case(s) need HR action', url('onboarding', ['status' => 'action']), 'ri-user-received-2-line', 'info'],
        ['eng_staff', 'client request(s) need review or authorisation', url('engagements', ['status' => 'action']), 'ri-service-line', 'info'],
        ['eng_client', 'item(s) need your signature or information', url('services'), 'ri-quill-pen-line', 'warning'],
        ['onboarding_mine', 'Your onboarding needs your attention', url('onboarding', ['a' => 'me']), 'ri-user-received-2-line', 'success'],
        ['policies_pending', 'HR polic(ies) need your acknowledgement', url('policies', ['pending' => 1]), 'ri-shield-check-line', 'info'],
        ['lock_reviews', 'candidate ownership conflict(s) to review', url('ownership', ['a' => 'reviews']), 'ri-lock-2-line', 'warning'],
        ['dup_reviews', 'possible duplicate candidate(s) to review', url('candidates', ['a' => 'quality']), 'ri-file-copy-2-line', 'warning'],
        ['hrd_approve', 'HR document(s) awaiting your approval', url('hrdocs', ['a' => 'docs', 'status' => 'Pending Approval']), 'ri-folder-shield-2-line', 'warning'],
        ['my_documents', 'document(s) shared with you need your response', url('mydocs'), 'ri-file-text-line', 'info'],
        ['my_induction', 'mandatory induction item(s) to complete', url('mydocs'), 'ri-book-open-line', 'info'],
        ['data_updates', 'optional data update(s) to review', url('maintenance'), 'ri-database-2-line', 'neutral'],
    ];
    $out = [];
    foreach ($items as [$key, $text, $href, $icon, $tone]) {
        $n = (int) ($c[$key] ?? 0);
        if ($n <= 0) continue;
        $out[] = ['key' => $key, 'count' => $n, 'text' => $key === 'onboarding_mine' ? $text : "$n $text", 'url' => $href, 'icon' => $icon, 'tone' => $tone === 'neutral' ? 'info' : $tone];
    }
    return $out;
}

/** Extra to-do counters (HR documents, the employee's own documents and induction), merged into pending_counts(). */
function notif_counts(): array
{
    $c = ['hrd_approve' => 0, 'my_documents' => 0, 'my_induction' => 0];
    if (!user() || !table_exists('hrm_hr_documents')) return $c;
    if (function_exists('hrd_can_approve') && hrd_can_approve()) {
        $c['hrd_approve'] = (int) val("SELECT COUNT(*) FROM hrm_hr_documents WHERE status = 'Pending Approval' AND (created_by IS NULL OR created_by <> ? OR ?)", [user()['id'], is_super_admin() ? 1 : 0]);
    }
    $c['my_documents'] = (int) val("SELECT COUNT(*) FROM hrm_hr_documents WHERE recipient_login_id = ? AND status IN ('Shared','Viewed') AND response <> 'none' AND (expires_at IS NULL OR expires_at > NOW())", [user()['id']]);
    if (!is_external() && ($emp = my_employee_id()) && function_exists('ind_status')) {
        $s = ind_status($emp);
        $c['my_induction'] = $s['mandatory'] - $s['done'];
    }
    return $c;
}

/** Bell total = action items shown + unread updates. */
function bell_total(): int
{
    return array_sum(array_column(action_items(), 'count')) + (user() ? notif_unread((int) user()['id']) : 0);
}
