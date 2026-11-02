<?php
/**
 * HR policies: a library every employee can read, with versioned e-acknowledgements.
 * A policy is shown to everyone, or to one department. An acknowledgement belongs to a version: publishing a
 * new version (changing the version number) asks everyone to acknowledge again.
 * Module: app/modules/policies.php. Formatting: policy_html() (headings, lists, bold, links — escaped).
 */

const POLICY_STATUSES = ['Draft', 'Published', 'Archived'];
/** category => [icon, colour] */
const POLICY_CATEGORIES = [
    'Code of Conduct'             => ['ri-scales-3-line', '#0077c0'],
    'Leave & Holidays'            => ['ri-calendar-event-line', '#0ea5e9'],
    'Attendance & Working Hours'  => ['ri-time-line', '#6366f1'],
    'Compensation & Benefits'     => ['ri-hand-coin-line', '#16a34a'],
    'Travel & Expenses'           => ['ri-flight-takeoff-line', '#f59e0b'],
    'IT & Data Security'          => ['ri-shield-keyhole-line', '#0f766e'],
    'POSH'                        => ['ri-women-line', '#db2777'],
    'Health & Safety'             => ['ri-first-aid-kit-line', '#dc2626'],
    'Remote & Hybrid Work'        => ['ri-home-wifi-line', '#8b5cf6'],
    'Exit & Separation'           => ['ri-door-open-line', '#64748b'],
    'General'                     => ['ri-file-text-line', '#475569'],
];

function policy_meta(string $category): array
{
    return POLICY_CATEGORIES[$category] ?? POLICY_CATEGORIES['General'];
}

/** SQL (alias p) for the published policies an employee may read: company-wide or their department. */
function policy_visible_sql(?int $employeeId): array
{
    $dept = $employeeId ? val('SELECT department_id FROM hrm_employees WHERE id = ?', [$employeeId]) : null;
    return ["p.status = 'Published' AND (p.department_id IS NULL OR p.department_id <=> ?)", [$dept]];
}

/** Published policies that need this employee's acknowledgement of the current version. */
function policies_pending(int $employeeId): array
{
    [$w, $params] = policy_visible_sql($employeeId);
    return all("SELECT p.* FROM hrm_policies p WHERE $w AND p.requires_ack = 1
                AND NOT EXISTS (SELECT 1 FROM hrm_policy_acks a WHERE a.policy_id = p.id AND a.employee_id = ? AND a.version = p.version)
                ORDER BY p.published_at DESC", [...$params, $employeeId]);
}

/** Active employees a policy applies to (everyone, or its department). */
function policy_audience(array $policy): array
{
    return all('SELECT e.id, e.emp_code, e.first_name, e.last_name, e.photo, e.email, d.name dept FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id
                WHERE e.status NOT IN ' . EMP_INACTIVE_SQL . ($policy['department_id'] ? ' AND e.department_id = ' . (int) $policy['department_id'] : '') . ' ORDER BY e.first_name, e.last_name');
}

/** [acknowledged, total] for the current version. */
function policy_ack_stats(array $policy): array
{
    $total = (int) val('SELECT COUNT(*) FROM hrm_employees e WHERE e.status NOT IN ' . EMP_INACTIVE_SQL . ($policy['department_id'] ? ' AND e.department_id = ' . (int) $policy['department_id'] : ''));
    $done = (int) val('SELECT COUNT(*) FROM hrm_policy_acks a JOIN hrm_employees e ON e.id = a.employee_id WHERE a.policy_id = ? AND a.version = ? AND e.status NOT IN ' . EMP_INACTIVE_SQL
        . ($policy['department_id'] ? ' AND e.department_id = ' . (int) $policy['department_id'] : ''), [$policy['id'], $policy['version']]);
    return [$done, $total];
}

/**
 * Policy text → HTML. Everything is escaped first; then a small Markdown subset is applied:
 * "## Heading", "### Sub-heading", "- item" / "1. item" lists, **bold**, *italic*, [text](https://link), blank line = new paragraph.
 */
function policy_html(?string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
    $html = '';
    $list = null;
    $para = [];
    $inline = function (string $s): string {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $s);
        return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/', fn($m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>', $s);
    };
    $flushPara = function () use (&$para, &$html, $inline) {
        if ($para) $html .= '<p>' . implode('<br>', array_map($inline, $para)) . '</p>';
        $para = [];
    };
    $closeList = function () use (&$list, &$html) {
        if ($list) $html .= "</$list>";
        $list = null;
    };
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') { $flushPara(); $closeList(); continue; }
        if (preg_match('/^(#{2,3})\s+(.+)$/', $t, $m)) {
            $flushPara(); $closeList();
            $tag = strlen($m[1]) === 2 ? 'h3' : 'h4';
            $html .= "<$tag>" . $inline($m[2]) . "</$tag>";
            continue;
        }
        if (preg_match('/^(?:[-*•]\s+|(\d+)[.)]\s+)(.+)$/u', $t, $m)) {
            $flushPara();
            $want = $m[1] !== '' ? 'ol' : 'ul';
            if ($list !== $want) { $closeList(); $html .= "<$want>"; $list = $want; }
            $html .= '<li>' . $inline($m[2]) . '</li>';
            continue;
        }
        $closeList();
        $para[] = $t;
    }
    $flushPara();
    $closeList();
    return $html;
}

/** Starter policies HR can adapt (Policies → New → template). Indian context; review with your legal adviser. */
function policy_templates(): array
{
    $co = setting('company_name', 'the Company');
    return [
        'conduct' => ['title' => 'Code of Conduct', 'category' => 'Code of Conduct', 'summary' => 'How we work together: integrity, respect, confidentiality and conflicts of interest.',
            'content' => "## Purpose\nThis Code sets out the standards of behaviour expected from everyone who works at $co, including employees, interns and consultants.\n\n## Our standards\n- Act with honesty and integrity in every interaction with colleagues, clients and partners.\n- Treat everyone with respect. Discrimination, bullying and harassment of any kind are not tolerated.\n- Protect confidential information about $co, our clients and our people.\n- Use company assets (laptops, accounts, money, time) only for legitimate business purposes.\n- Comply with all applicable laws and regulations.\n\n## Conflicts of interest\nDisclose any personal, financial or family interest that could influence your decisions at work to your manager and HR before acting on it. Outside employment or business activity needs prior written approval from HR.\n\n## Gifts and hospitality\nDo not offer or accept gifts, entertainment or payments that could influence a business decision. Gifts above **₹2,000** in value must be declared to HR.\n\n## Raising concerns\nReport any breach of this Code to your manager, HR or the leadership team. Reports made in good faith are protected; retaliation is itself a breach of this Code.\n\n## Consequences\nBreaches may lead to disciplinary action, up to and including termination of employment."],
        'leave' => ['title' => 'Leave Policy', 'category' => 'Leave & Holidays', 'summary' => 'Leave types, entitlements, how to apply, approvals and carry-forward rules.',
            'content' => "## Scope\nApplies to all permanent employees of $co. Interns and consultants follow the terms in their engagement letter.\n\n## Leave year\nThe leave year runs from **1 January to 31 December**. Leave is credited as shown in the HRMS under My Leaves.\n\n## Types of leave\n- **Casual leave:** for short, unplanned personal needs.\n- **Sick leave:** for illness. A medical certificate is required for more than 2 consecutive days.\n- **Earned / privilege leave:** planned leave, applied for at least 7 days in advance.\n- **Maternity and paternity leave:** as per the Maternity Benefit Act and company policy.\n- **Leave without pay:** only with the approval of your manager and HR.\n\n## How to apply\n1. Apply in the HRMS (My Leaves → Apply) before the leave starts, except in emergencies.\n2. Your manager approves or rejects the request in the HRMS.\n3. Unapproved absence is treated as leave without pay.\n\n## Carry forward and encashment\nUnused earned leave can be carried forward up to the limit set by HR. Casual and sick leave lapse at the end of the leave year.\n\n## Holidays\nThe holiday calendar is published in the HRMS at the start of each year."],
        'posh' => ['title' => 'Prevention of Sexual Harassment (POSH) Policy', 'category' => 'POSH', 'summary' => 'Our zero-tolerance policy under the POSH Act, 2013, and how to raise a complaint with the Internal Committee.',
            'content' => "## Commitment\n$co is committed to a workplace free from sexual harassment, in line with the Sexual Harassment of Women at Workplace (Prevention, Prohibition and Redressal) Act, 2013.\n\n## What is sexual harassment\nUnwelcome acts or behaviour, directly or by implication, such as:\n- physical contact and advances;\n- a demand or request for sexual favours;\n- sexually coloured remarks;\n- showing pornography;\n- any other unwelcome physical, verbal or non-verbal conduct of a sexual nature.\n\n## Internal Committee (IC)\nThe Internal Committee receives and inquires into complaints. Its members and contact details are displayed in the office and available from HR.\n\n## How to complain\n1. Submit a written complaint to the IC within 3 months of the incident.\n2. The IC completes its inquiry within 90 days, following principles of natural justice.\n3. All proceedings are confidential.\n\n## Protection\nNo one will face retaliation for raising a complaint or taking part in an inquiry in good faith."],
        'it' => ['title' => 'IT & Data Security Policy', 'category' => 'IT & Data Security', 'summary' => 'Passwords, devices, e-mail, client data and how to report a security incident.',
            'content' => "## Purpose\nTo protect the information and systems of $co and our clients.\n\n## Passwords and accounts\n- Use a strong, unique password for every work account and never share it.\n- Turn on two-factor authentication wherever it is available.\n- Lock your screen whenever you leave your device.\n\n## Devices\n- Keep your operating system and software up to date.\n- Install software only from approved sources.\n- Report a lost or stolen device to IT and HR **immediately**.\n\n## Data handling\n- Store work files only in approved company systems.\n- Do not copy client or employee personal data to personal devices or accounts.\n- Share confidential information only with people who need it for their work.\n\n## E-mail and phishing\nBe careful with unexpected links and attachments. Report suspicious e-mails to IT; never enter your password on a page you reached from an e-mail link.\n\n## Incidents\nReport any suspected data breach or security incident to IT straight away, even if you are not sure."],
        'wfh' => ['title' => 'Remote & Hybrid Work Policy', 'category' => 'Remote & Hybrid Work', 'summary' => 'Who can work from home, how to request it, availability and attendance rules.',
            'content' => "## Eligibility\nRoles that can be performed remotely may work from home with the approval of the reporting manager.\n\n## Requesting WFH\nRequest work from home in advance through your manager. Your attendance is marked as WFH in the HRMS.\n\n## Expectations\n- Be available on chat and calls during core hours.\n- Punch in and out in the HRMS as you would in the office.\n- Work from a safe, private place and protect company information.\n\n## Equipment\nCompany equipment remains the property of $co and must be returned when requested or on exit."],
        'travel' => ['title' => 'Travel & Expense Policy', 'category' => 'Travel & Expenses', 'summary' => 'Approvals, eligible expenses, limits and how to claim reimbursement.',
            'content' => "## Approval\nBusiness travel needs prior approval from your manager.\n\n## Eligible expenses\n- Travel by the most economical practical mode.\n- Hotel stay within the limits set for your grade.\n- Daily allowance for meals as per your grade.\n- Local conveyance for business purposes.\n\n## Claims\n1. Submit claims in the HRMS (My Expenses) within **15 days** of the expense.\n2. Attach original bills or clear photos of them.\n3. Claims are approved by your manager and reimbursed with the next payroll.\n\n## Not reimbursed\nPersonal expenses, fines and penalties, alcohol and expenses without bills."],
    ];
}
