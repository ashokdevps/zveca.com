<?php
/**
 * Helpdesk rules: who sees which ticket, SLA targets, numbering and notifications.
 * Requesters can be any login (staff, client, vendor or candidate); agents hold manage_support.
 */

const TICKET_STATUSES = ['Open', 'In Progress', 'Waiting on Customer', 'Resolved', 'Closed'];
const TICKET_ACTIVE = ['Open', 'In Progress', 'Waiting on Customer'];
const TICKET_PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

/** Ticket categories = the areas of the product a person can ask about, per audience. */
function ticket_categories(?string $audience = null): array
{
    $all = [
        'Login & account'       => ['staff', 'client', 'vendor', 'candidate'],
        'Attendance & punch'    => ['staff'],
        'Leave'                 => ['staff'],
        'Payroll & payslips'    => ['staff'],
        'Expenses'              => ['staff'],
        'Employee records'      => ['staff'],
        'Recruitment'           => ['staff', 'client'],
        'Projects & tasks'      => ['staff', 'client'],
        'Invoices & payments'   => ['staff', 'client'],
        'Training'              => ['staff'],
        'Vendor portal'         => ['staff', 'vendor'],
        'Job applications'      => ['staff', 'candidate'],
        'Reports'               => ['staff'],
        'Bug / something broken' => ['staff', 'client', 'vendor', 'candidate'],
        'Feature request'       => ['staff', 'client', 'vendor', 'candidate'],
        'General'               => ['staff', 'client', 'vendor', 'candidate'],
    ];
    $audience ??= audience_of(role());
    return array_keys(array_filter($all, fn($a) => in_array($audience, $a, true) || $audience === 'agent'));
}

function sla_hours(string $priority): int
{
    $default = ['Critical' => 4, 'High' => 8, 'Medium' => 24, 'Low' => 72][$priority] ?? 24;
    return max(1, (int) setting('sla_hours_' . $priority, $default));
}

function next_ticket_no(): string
{
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) setting('ticket_prefix', 'TKT'))) ?: 'TKT';
    $max = (int) val('SELECT MAX(CAST(SUBSTRING(ticket_no, ?) AS UNSIGNED)) FROM hrm_tickets WHERE ticket_no LIKE ?', [strlen($prefix) + 2, $prefix . '-%']);
    return $prefix . '-' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
}

function is_agent(): bool
{
    return can('manage_support');
}

/** Agents see every ticket; everybody else only their own. */
function ticket_visible(array $t): bool
{
    return is_agent() || (int) $t['requester_id'] === (int) (user()['id'] ?? 0);
}

/** SLA state for a ticket: [label, tone]. */
function sla_state(array $t): array
{
    if (!in_array($t['status'], TICKET_ACTIVE, true)) {
        $late = $t['resolved_at'] && $t['sla_due_at'] && $t['resolved_at'] > $t['sla_due_at'];
        return [$late ? 'Resolved late' : 'Within SLA', $late ? 'warning' : 'success'];
    }
    if ($t['status'] === 'Waiting on Customer') return ['Paused', 'neutral'];
    if (!$t['sla_due_at']) return ['—', 'neutral'];
    $left = strtotime($t['sla_due_at']) - time();
    if ($left < 0) return ['Breached ' . ltrim(time_ago($t['sla_due_at']), '-'), 'danger'];
    $h = intdiv($left, 3600);
    return [($h >= 24 ? intdiv($h, 24) . 'd ' . ($h % 24) . 'h' : $h . 'h ' . intdiv($left % 3600, 60) . 'm') . ' left', $left < 7200 ? 'warning' : 'info'];
}

/** Display name for any login (employee name, candidate name or username). */
function login_name(?int $id): string
{
    static $cache = [];
    if (!$id) return 'System';
    return $cache[$id] ??= (function () use ($id) {
        $r = one('SELECT l.username, l.usertype, e.first_name, e.last_name, t.full_name, c.name client, v.name vendor FROM it_login l
                  LEFT JOIN hrm_employees e ON e.id = l.employee_id LEFT JOIN hrm_talent t ON t.login_id = l.id
                  LEFT JOIN hrm_clients c ON c.id = l.client_id LEFT JOIN hrm_vendors v ON v.id = l.vendor_id WHERE l.id = ?', [$id]);
        if (!$r) return 'Deleted user';
        return $r['first_name'] ? trim($r['first_name'] . ' ' . $r['last_name']) : ($r['full_name'] ?: $r['username']);
    })();
}

/** "Client · Acme Ltd" style label describing who a requester is. */
function login_context(?int $id): string
{
    $r = $id ? one('SELECT l.usertype, l.email, e.emp_code, c.name client, v.name vendor FROM it_login l LEFT JOIN hrm_employees e ON e.id = l.employee_id
                    LEFT JOIN hrm_clients c ON c.id = l.client_id LEFT JOIN hrm_vendors v ON v.id = l.vendor_id WHERE l.id = ?', [$id]) : null;
    if (!$r) return '';
    $role = normalize_role($r['usertype']);
    return ROLES[$role] . ($r['client'] ? ' · ' . $r['client'] : '') . ($r['vendor'] ? ' · ' . $r['vendor'] : '') . ($r['emp_code'] ? ' · ' . $r['emp_code'] : '');
}

/** Support agents who can be assigned tickets (id => name). */
function agent_options(): array
{
    $out = [];
    foreach (all("SELECT * FROM it_login WHERE status = 'active' ORDER BY username") as $l) {
        if (in_array('manage_support', permissions_for($l), true)) {
            $out[$l['id']] = login_name((int) $l['id']);
        }
    }
    return $out;
}

/** E-mail helper for ticket events; failures never break the request. */
function ticket_notify(array $t, string $toLoginId, string $what): void
{
    if ((int) $toLoginId !== (int) (user()['id'] ?? 0)) notify((int) $toLoginId, 'ticket', "{$t['ticket_no']}: " . mb_strimwidth((string) $t['subject'], 0, 80, '…'), $what, url('support', ['a' => 'view', 'id' => $t['id']]), 'ri-customer-service-2-line', 'info');
    $email = val('SELECT email FROM it_login WHERE id = ?', [$toLoginId]);
    if (!$email || (int) $toLoginId === (int) (user()['id'] ?? 0)) return;
    $link = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('support', ['a' => 'view', 'id' => $t['id']]);
    send_mail($email, "[{$t['ticket_no']}] {$t['subject']}", "$what\n\nTicket: {$t['ticket_no']} — {$t['subject']}\nStatus: {$t['status']}\n\nOpen the ticket: $link\n\n— " . setting('company_name', 'HRMS') . ' Support');
}

/** Numbers for the bell / sidebar badges. */
function support_counts(): array
{
    $me = (int) (user()['id'] ?? 0);
    $c = ['support_queue' => 0, 'my_ticket_updates' => 0];
    if (is_agent()) {
        $c['support_queue'] = (int) val("SELECT COUNT(*) FROM hrm_tickets WHERE status IN ('Open','In Progress') AND (assignee_id IS NULL OR assignee_id = ?)", [$me]);
    }
    if (can('raise_tickets')) {
        // Tickets that need the requester: a question from support, or a resolution to confirm / rate.
        $c['my_ticket_updates'] = (int) val("SELECT COUNT(*) FROM hrm_tickets WHERE requester_id = ? AND (status = 'Waiting on Customer' OR (status = 'Resolved' AND rating IS NULL))", [$me]);
    }
    return $c;
}

/** Adds a line to a ticket's thread. Events are one-line status changes; internal notes are agent-only. */
function ticket_message(int $ticketId, string $body, bool $internal = false, bool $event = false, ?string $attachment = null): void
{
    insert('hrm_ticket_messages', ['ticket_id' => $ticketId, 'user_id' => user()['id'] ?? null, 'body' => $body,
        'internal' => $internal ? 1 : 0, 'event' => $event ? 1 : 0, 'attachment' => $attachment]);
    q('UPDATE hrm_tickets SET last_activity_at = NOW() WHERE id = ?', [$ticketId]);
}

/**
 * Moves a ticket to a new status and keeps the SLA clock honest:
 * the clock pauses while "Waiting on Customer" and the paused time is added back to the due date.
 */
function ticket_set_status(array $t, string $status): void
{
    if ($status === $t['status'] || !in_array($status, TICKET_STATUSES, true)) return;
    $now = date('Y-m-d H:i:s');
    $d = ['status' => $status];
    if ($status === 'Waiting on Customer') {
        $d['sla_paused_at'] = $now;
    } elseif ($t['sla_paused_at']) {
        $paused = time() - strtotime($t['sla_paused_at']);
        if ($t['sla_due_at']) $d['sla_due_at'] = date('Y-m-d H:i:s', strtotime($t['sla_due_at']) + $paused);
        $d['sla_paused_at'] = null;
    }
    if ($status === 'Resolved') $d['resolved_at'] = $now;
    if ($status === 'Closed') { $d['closed_at'] = $now; $d['resolved_at'] = $t['resolved_at'] ?: $now; }
    if (in_array($status, TICKET_ACTIVE, true)) { $d['resolved_at'] = null; $d['closed_at'] = null; }
    update('hrm_tickets', $d, (int) $t['id']);
    ticket_message((int) $t['id'], 'changed the status to ' . $status, false, true);
    log_activity('ticket_' . strtolower(str_replace(' ', '_', $status)), 'ticket', (int) $t['id']);
}
