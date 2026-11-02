<?php
/**
 * Employee onboarding: recruitment → offer → candidate self-service → HR verification → employee activation.
 *
 *   Phase 1  Final selection → offer letter (template) → approval → e-mail with secure link → e-mail OTP → accept / decline
 *   Phase 2  Personal → education & employment → bank & statutory → documents → e-signatures → final submission
 *   Phase 3  Document verification → compliance check → HR final approval → employee ID → profile activation → joining & induction
 *
 * One hrm_onboardings row per offer ("case"). Uploaded files, e-signatures and every event hang off it, so the case
 * is also the audit record of how a person was hired. The candidate works in the candidate portal (dashboard/onboarding);
 * the secure link in the offer e-mail lands on /onboarding (onboarding.php), which verifies the e-mail with a one-time code.
 */

/** Case statuses, in the order a case normally moves through them. */
const ONB_STATUSES = ['Draft', 'Pending Approval', 'Changes Requested', 'Approved', 'Offer Sent', 'Accepted', 'Submitted',
    'Correction Requested', 'Ready to Join', 'Activated', 'Joined', 'Declined', 'Expired', 'Withdrawn'];

/** The offer can still be edited / revised (before the candidate accepts). */
const ONB_REVISABLE = ['Draft', 'Changes Requested', 'Approved', 'Offer Sent', 'Expired', 'Declined'];
/** Statuses the candidate can see in the portal. */
const ONB_CANDIDATE_VISIBLE = ['Offer Sent', 'Accepted', 'Declined', 'Expired', 'Submitted', 'Correction Requested', 'Ready to Join', 'Activated', 'Joined'];
/** The candidate may edit their onboarding form. */
const ONB_CANDIDATE_EDIT = ['Accepted', 'Correction Requested'];
/** Finished cases (no further action). */
const ONB_CLOSED = ['Joined', 'Declined', 'Withdrawn'];

/** Candidate-side steps (Phase 1 acceptance + Phase 2). */
const ONB_STEPS = [
    'offer'     => ['Offer', 'ri-file-text-line'],
    'personal'  => ['Personal information', 'ri-user-3-line'],
    'education' => ['Education & employment', 'ri-graduation-cap-line'],
    'bank'      => ['Bank & statutory', 'ri-bank-line'],
    'documents' => ['Documents', 'ri-folder-upload-line'],
    'esign'     => ['E-signature', 'ri-quill-pen-line'],
    'submit'    => ['Review & submit', 'ri-send-plane-2-line'],
];

const ONB_TEMPLATE_KINDS = ['offer' => 'Offer letter', 'agreement' => 'Employment agreement', 'nda' => 'Non-disclosure agreement'];
const ONB_GENDERS = ['Male', 'Female', 'Other', 'Prefer not to say'];
const ONB_RELATIONS = ['Father', 'Mother', 'Spouse', 'Sibling', 'Son', 'Daughter', 'Guardian', 'Friend', 'Other'];
const ONB_QUALIFICATIONS = ['10th / SSC', '12th / HSC', 'Diploma', 'Graduate (Bachelor\'s)', 'Post-graduate (Master\'s)', 'Doctorate (PhD)', 'Professional certification', 'Other'];
const ONB_ACCOUNT_TYPES = ['Savings', 'Current', 'Salary'];

/* Default checklists (Settings → Onboarding). One item per line: key | label | required (yes / no / experienced) | hint */
const ONB_DEFAULT_DOCUMENTS = "photo | Passport-size photograph | yes | Clear, recent photo on a plain background (JPG or PNG)
pan | PAN card | yes | Identity proof; also used for TDS
address_proof | Address proof | yes | Aadhaar (masked), passport, voter ID or driving licence
education | Highest education certificate | yes | Degree / provisional certificate
marksheets | Marksheets (10th, 12th, graduation) | no | One PDF with all marksheets is fine
experience | Experience / relieving letter | experienced | From your last employer
payslips | Last 3 months' payslips | experienced | From your current / last employer
bank_proof | Cancelled cheque or bank passbook | yes | Showing your name, account number and IFSC";

const ONB_DEFAULT_COMPLIANCE = "form11 | EPF Form 11 declaration checked (previous PF / UAN details) | yes
nomination | PF / gratuity nomination recorded (Form 2 / Form F) | yes
esi | ESI declaration taken (only if gross pay is within the ESI wage limit) | no
bgv | Background verification cleared | no
references | Reference check completed | no";

const ONB_DEFAULT_TASKS = "Attend the HR induction session | 1
Set up Face ID / punch in from the HRMS | 1
Read and acknowledge the HR policies | 3
Meet your reporting manager and team | 2
Complete the role training plan | 30";

const ONB_DEFAULT_ACCESS = "Work e-mail account created
Laptop / equipment issued
ID card and access card issued
Added to team chat and project tools
Seat / workstation allocated";

/* ───────────────────────── Settings & checklists ───────────────────────── */

/** Parses a "key | label | required | hint" list from a setting. Returns key => [label, required, hint]. */
function onb_checklist(string $setting, string $default): array
{
    return onb_checklist_from_text((string) setting($setting, $default)) ?: onb_checklist_from_text($default);
}

function onb_checklist_from_text(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (($parts[0] ?? '') === '') continue;
        $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($parts[0])), '_');
        if ($key === '' || isset($out[$key])) continue;
        $req = strtolower($parts[2] ?? 'no');
        $out[$key] = ['label' => $parts[1] ?? $parts[0], 'required' => in_array($req, ['yes', 'experienced'], true) ? $req : 'no', 'hint' => $parts[3] ?? ''];
    }
    return $out;
}

/** Document types the candidate uploads. */
function onb_document_types(): array
{
    return onb_checklist('onboarding_documents', ONB_DEFAULT_DOCUMENTS);
}

/** Manual compliance items HR ticks off. */
function onb_compliance_items(): array
{
    return onb_checklist('onboarding_compliance', ONB_DEFAULT_COMPLIANCE);
}

/** Initial tasks created for a new joiner: [[title, days after joining], …]. */
function onb_task_list(): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) setting('onboarding_tasks', ONB_DEFAULT_TASKS)) as $line) {
        $p = array_map('trim', explode('|', $line));
        if (($p[0] ?? '') !== '') $out[] = [mb_substr($p[0], 0, 200), max(0, min(365, (int) ($p[1] ?? 7)))];
    }
    return $out;
}

/** Joining-day system access checklist. */
function onb_access_items(): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) setting('onboarding_access_items', ONB_DEFAULT_ACCESS)))));
}

function onb_approval_required(): bool
{
    return setting('offer_approval', '1') === '1';
}

/** 'approval': employee ID + profile at HR final approval (default). 'joining': only when joining is confirmed. */
function onb_activation_trigger(): string
{
    return setting('onboarding_activation', 'approval') === 'joining' ? 'joining' : 'approval';
}

/* ───────────────────────── Loading ───────────────────────── */

/** A case with its candidate, job, department, designation and manager names. */
function onb_find(int $id): ?array
{
    $o = one("SELECT o.*, j.title job_title, d.name dept, g.title des, g.level des_level, CONCAT(m.first_name, ' ', COALESCE(m.last_name, '')) mgr,
                     m.email mgr_email, c.stage cand_stage, l.username creator, l.email creator_email
              FROM hrm_onboardings o LEFT JOIN hrm_jobs j ON j.id = o.job_id LEFT JOIN hrm_departments d ON d.id = o.department_id
              LEFT JOIN hrm_designations g ON g.id = o.designation_id LEFT JOIN hrm_employees m ON m.id = o.manager_id
              LEFT JOIN hrm_candidates c ON c.id = o.candidate_id LEFT JOIN it_login l ON l.id = o.created_by WHERE o.id = ?", [$id]);
    if (!$o) return null;
    $o['profile_data'] = json_decode((string) $o['profile'], true) ?: [];
    $o['salary_data'] = json_decode((string) $o['salary'], true) ?: [];
    $o['compliance_data'] = json_decode((string) $o['compliance'], true) ?: [];
    $o['access_data'] = json_decode((string) $o['access_checklist'], true) ?: [];
    // An offer that was not answered in time expires (checked whenever the case is opened).
    if ($o['status'] === 'Offer Sent' && $o['offer_expires'] && $o['offer_expires'] < date('Y-m-d')) {
        q("UPDATE hrm_onboardings SET status = 'Expired' WHERE id = ? AND status = 'Offer Sent'", [$id]);
        onb_log($id, 'offer_expired', 'Not answered by ' . fdate($o['offer_expires']), 'system');
        $o['status'] = 'Expired';
    }
    return $o;
}

/** The candidate portal's current case for a login (the newest one they can see). */
function onb_for_login(int $loginId, bool $fresh = false): ?array
{
    static $cache = [];
    if ($fresh || !array_key_exists($loginId, $cache)) {
        $id = val('SELECT id FROM hrm_onboardings WHERE login_id = ? AND status IN ' . sql_list(ONB_CANDIDATE_VISIBLE) . ' ORDER BY id DESC LIMIT 1', [$loginId]);
        $cache[$loginId] = $id ? onb_find((int) $id) : null;
    }
    return $cache[$loginId];
}

/** Open case for a candidate (anything not declined / withdrawn), or null. */
function onb_open_for_candidate(int $candidateId): ?array
{
    return one("SELECT id, status FROM hrm_onboardings WHERE candidate_id = ? AND status NOT IN ('Declined','Withdrawn') ORDER BY id DESC LIMIT 1", [$candidateId]);
}

/**
 * Keeps the recruitment pipeline in step with the case (Offered / Hired / Rejected) and records it in the stage
 * history. Unlike move_candidate_stage() it sends no "status changed" e-mail: onboarding sends its own messages.
 */
function onb_candidate_stage(array $o, string $stage, string $note, array $extra = []): void
{
    $c = $o['candidate_id'] ? one('SELECT id, stage FROM hrm_candidates WHERE id = ?', [$o['candidate_id']]) : null;
    if (!$c || !in_array($stage, CANDIDATE_STAGES, true)) return;
    $data = array_filter($extra, fn($v) => $v !== null && $v !== '');
    if ($c['stage'] !== $stage) $data += ['stage' => $stage, 'stage_changed_at' => date('Y-m-d H:i:s')];
    if ($data) update('hrm_candidates', $data, (int) $c['id']);
    insert('hrm_stage_history', ['candidate_id' => $c['id'], 'from_stage' => $c['stage'], 'to_stage' => $stage, 'note' => mb_substr($note, 0, 255), 'user_id' => $_SESSION['uid'] ?? null]);
}

function onb_save(int $id, array $data): void
{
    foreach (['profile', 'salary', 'compliance', 'access_checklist'] as $json) {
        if (isset($data[$json]) && is_array($data[$json])) $data[$json] = json_encode($data[$json], JSON_UNESCAPED_UNICODE);
    }
    update('hrm_onboardings', $data, $id);
    // Client Document Centre: record / follow the offer letter for the candidate's client (offers sent, accepted, joined).
    if (isset($data['status']) && function_exists('offer_share_sync')) {
        try { offer_share_sync($id); } catch (Throwable $e) { error_log('Offer letter share: ' . $e->getMessage()); }
    }
}

/** Audit trail entry. $actor: hr | candidate | system. */
function onb_log(int $id, string $event, ?string $note = null, string $actor = 'hr'): void
{
    insert('hrm_onboarding_events', ['onboarding_id' => $id, 'event' => $event, 'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        'actor' => $actor, 'user_id' => $_SESSION['uid'] ?? null, 'ip' => client_ip()]);
}

const ONB_EVENT_LABELS = [
    'created' => 'Onboarding started', 'offer_updated' => 'Offer details updated', 'submitted_for_approval' => 'Offer sent for approval',
    'offer_approved' => 'Offer approved', 'changes_requested' => 'Changes requested on the offer', 'offer_sent' => 'Offer e-mailed to the candidate',
    'offer_resent' => 'Offer link sent again', 'offer_revised' => 'Offer reopened for revision', 'offer_expired' => 'Offer expired',
    'link_opened' => 'Secure link opened', 'email_verified' => 'E-mail verified with a one-time code', 'account_created' => 'Candidate portal account created',
    'offer_accepted' => 'Offer accepted and e-signed', 'offer_declined' => 'Offer declined', 'step_saved' => 'Form section saved',
    'document_uploaded' => 'Document uploaded', 'document_signed' => 'Document e-signed', 'form_submitted' => 'Onboarding form submitted for HR review',
    'document_verified' => 'Document verified', 'document_rejected' => 'Document rejected', 'correction_requested' => 'Corrections requested from the candidate',
    'compliance_updated' => 'Compliance checklist updated', 'hr_approved' => 'HR final approval given', 'employee_created' => 'Employee ID generated and profile activated',
    'joined' => 'Joining confirmed', 'withdrawn' => 'Offer withdrawn', 'induction_saved' => 'Joining & induction details saved',
];

function onb_events(int $id): array
{
    return all('SELECT e.*, l.username FROM hrm_onboarding_events e LEFT JOIN it_login l ON l.id = e.user_id WHERE e.onboarding_id = ? ORDER BY e.id DESC', [$id]);
}

/* ───────────────────────── Offer: salary, templates, rendering ───────────────────────── */

/**
 * Annual CTC → monthly salary structure. CTC includes the employer's PF contribution:
 *   basic = basic% of monthly CTC, HRA = HRA% of basic, employer PF = PF% of min(basic, PF wage ceiling),
 *   special allowance = the rest. Matches how Payroll calculates PF (Settings → Payroll).
 */
function onb_salary(float $ctcAnnual, bool $pf = true): array
{
    $m = round($ctcAnnual / 12, 2);
    $basicPct = max(10, min(80, (float) setting('salary_basic_pct', 40)));
    $hraPct = max(0, min(60, (float) setting('salary_hra_pct', 50)));
    $basic = round($m * $basicPct / 100);
    $employerPf = $pf ? round(min($basic, (float) setting('pf_wage_ceiling', 15000)) * (float) setting('pf_percent', 12) / 100) : 0;
    $hra = round($basic * $hraPct / 100);
    $gross = $m - $employerPf;
    $special = round($gross - $basic - $hra);
    if ($special < 0) { $hra = max(0, $hra + $special); $special = max(0, round($gross - $basic - $hra)); }
    return ['monthly_ctc' => $m, 'basic' => $basic, 'hra' => $hra, 'special_allowance' => $special, 'gross' => round($basic + $hra + $special),
        'employer_pf' => $employerPf, 'employee_pf' => $employerPf, 'pf' => $pf, 'net' => round($basic + $hra + $special - $employerPf)];
}

/** Salary annexure (HTML table) for letters and the case page. */
function onb_salary_table(array $s): string
{
    if (!$s) return '';
    $rows = [['Basic salary', $s['basic']], ['House rent allowance (HRA)', $s['hra']], ['Special allowance', $s['special_allowance']]];
    $html = '<table class="table table-sm onb-salary"><thead><tr><th>Component</th><th class="text-end">Monthly</th><th class="text-end">Annual</th></tr></thead><tbody>';
    foreach ($rows as [$l, $v]) $html .= '<tr><td>' . e($l) . '</td><td class="text-end">' . money($v) . '</td><td class="text-end">' . money($v * 12) . '</td></tr>';
    $html .= '<tr class="fw-semibold"><td>Gross salary (A)</td><td class="text-end">' . money($s['gross']) . '</td><td class="text-end">' . money($s['gross'] * 12) . '</td></tr>';
    if (!empty($s['pf'])) $html .= '<tr><td>Employer PF contribution (B)</td><td class="text-end">' . money($s['employer_pf']) . '</td><td class="text-end">' . money($s['employer_pf'] * 12) . '</td></tr>';
    $html .= '<tr class="fw-bold"><td>Cost to company (A' . (!empty($s['pf']) ? ' + B' : '') . ')</td><td class="text-end">' . money($s['monthly_ctc']) . '</td><td class="text-end">' . money($s['monthly_ctc'] * 12) . '</td></tr>';
    return $html . '</tbody></table>' . (!empty($s['pf']) ? '<p class="small text-muted mb-0">Employee PF of ' . money($s['employee_pf']) . ' per month is deducted from gross salary, along with professional tax and TDS as applicable.</p>' : '');
}

function onb_templates(string $kind, bool $activeOnly = true): array
{
    return all('SELECT * FROM hrm_onboarding_templates WHERE kind = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY is_default DESC, name', [$kind]);
}

/** The active default template of a kind (agreement / NDA are signed during onboarding when one exists). */
function onb_default_template(string $kind): ?array
{
    return one('SELECT * FROM hrm_onboarding_templates WHERE kind = ? AND active = 1 ORDER BY is_default DESC, id LIMIT 1', [$kind]);
}

/** Placeholder values for a case. Keys are used as {{key}} in templates. */
function onb_placeholders(array $o): array
{
    $p = $o['profile_data']['personal'] ?? [];
    $name = trim(implode(' ', array_filter([$p['first_name'] ?? null, $p['middle_name'] ?? null, $p['last_name'] ?? null]))) ?: $o['full_name'];
    $track = $o['probation_track'] ?: (in_array($o['des_level'] ?? '', SENIOR_LEVELS, true) ? 'Senior' : 'Junior');
    return [
        'candidate_name' => $name, 'first_name' => explode(' ', $name)[0], 'email' => $o['email'], 'phone' => $o['phone'],
        'address' => trim(implode(', ', array_filter([$p['current_address'] ?? null, $p['current_city'] ?? null, $p['current_state'] ?? null, $p['current_pincode'] ?? null]))),
        'designation' => $o['des'] ?: ($o['job_title'] ?: '—'), 'department' => $o['dept'] ?: '—', 'manager' => trim((string) $o['mgr']) ?: 'your reporting manager',
        'employment_type' => $o['employment_type'] ?: 'Full-time', 'work_location' => $o['work_location'] ?: (setting('company_address') ? 'our office' : '—'),
        'ctc' => money($o['ctc_annual']), 'ctc_words' => $o['ctc_annual'] !== null ? amount_in_words((float) $o['ctc_annual']) : '',
        'monthly_gross' => money($o['salary_data']['gross'] ?? null),
        'joining_date' => fdate($o['joining_date'], 'd F Y'), 'offer_expiry' => fdate($o['offer_expires'], 'd F Y'),
        'probation_months' => (string) probation_months($track), 'notice_period' => setting('notice_period_days', '30') . ' days',
        'offer_ref' => $o['offer_ref'] ?: 'DRAFT', 'today' => fdate(date('Y-m-d'), 'd F Y'), 'offer_date' => fdate($o['approved_at'] ?: date('Y-m-d'), 'd F Y'),
        'company_name' => setting('company_name', 'Adorable IT Services'), 'company_address' => (string) setting('company_address', ''),
        'company_email' => (string) setting('company_email', ''), 'hr_name' => setting('onboarding_signatory', 'Human Resources'),
    ];
}

/**
 * Placeholder values backed by records only (null = not on record), used for offer letters and HR documents so missing
 * details are flagged for HR instead of being filled with a guess. onb_placeholders() keeps its wording for agreements / NDA.
 */
function onb_placeholder_values(array $o): array
{
    $p = $o['profile_data']['personal'] ?? [];
    $name = trim(implode(' ', array_filter([$p['first_name'] ?? null, $p['middle_name'] ?? null, $p['last_name'] ?? null]))) ?: $o['full_name'];
    $track = $o['probation_track'] ?: (in_array($o['des_level'] ?? '', SENIOR_LEVELS, true) ? 'Senior' : 'Junior');
    $sig = !empty($o['signatory_id']) ? sig_find((int) $o['signatory_id'], false) : null;
    $emp = !empty($o['employee_id']) ? one('SELECT emp_code, official_email, official_email_status FROM hrm_employees WHERE id = ?', [$o['employee_id']]) : null;
    $table = onb_salary_table($o['salary_data'] ?? []);
    return [
        'candidate_name' => $name, 'first_name' => explode(' ', $name)[0], 'email' => $o['email'], 'personal_email' => $o['email'], 'phone' => $o['phone'] ?: null,
        'address' => trim(implode(', ', array_filter([$p['current_address'] ?? null, $p['current_city'] ?? null, $p['current_state'] ?? null, $p['current_pincode'] ?? null]))) ?: null,
        'designation' => $o['des'] ?: ($o['job_title'] ?: null), 'department' => $o['dept'] ?: null, 'manager' => trim((string) $o['mgr']) ?: null,
        'employment_type' => $o['employment_type'] ?: null, 'work_location' => $o['work_location'] ?: null,
        'ctc' => $o['ctc_annual'] !== null ? money($o['ctc_annual']) : null, 'ctc_words' => $o['ctc_annual'] !== null ? amount_in_words((float) $o['ctc_annual']) : null,
        'monthly_gross' => isset($o['salary_data']['gross']) ? money($o['salary_data']['gross']) : null, 'salary_table' => $table !== '' ? $table : null,
        'joining_date' => $o['joining_date'] ? fdate($o['joining_date'], 'd F Y') : null, 'offer_expiry' => $o['offer_expires'] ? fdate($o['offer_expires'], 'd F Y') : null,
        'acceptance_deadline' => $o['offer_expires'] ? fdate($o['offer_expires'], 'd F Y') : null,
        'probation_months' => (string) probation_months($track), 'notice_period' => setting('notice_period_days') ? setting('notice_period_days') . ' days' : null,
        'working_hours' => setting('office_start') && setting('office_end') ? date('h:i A', strtotime(setting('office_start'))) . ' to ' . date('h:i A', strtotime(setting('office_end'))) . ', Monday to Friday' : null,
        'offer_ref' => $o['offer_ref'] ?: 'DRAFT', 'today' => fdate(date('Y-m-d'), 'd F Y'), 'offer_date' => fdate($o['approved_at'] ?: date('Y-m-d'), 'd F Y'),
        'issue_date' => fdate($o['approved_at'] ?: date('Y-m-d'), 'd F Y'),
        'company_name' => setting('company_name') ?: null, 'company_address' => setting('company_address') ?: null, 'company_email' => setting('company_email') ?: null,
        'hr_name' => $sig['name'] ?? (setting('onboarding_signatory') ?: null),
        'signatory_name' => $sig['name'] ?? (setting('onboarding_signatory') ?: null), 'signatory_designation' => $sig['designation'] ?? null, 'signature' => $sig ? (sig_img_html($sig) ?: null) : null,
        'employee_id' => $emp['emp_code'] ?? null, 'official_email' => ($emp['official_email_status'] ?? '') === 'Provisioned' ? $emp['official_email'] : null,
    ];
}

/**
 * The offer letter text for a case that is not yet approved: HR's edited draft if there is one, otherwise the chosen template
 * (HR Document Centre library template, or an onboarding text template) with verified values; missing ones are highlighted.
 * $withSignature adds the chosen signatory's signature image (data URI) — used for preview and when the letter is fixed.
 */
function onb_offer_html(array $o, bool $withSignature = true): string
{
    $vals = onb_placeholder_values($o);
    if (!$withSignature) $vals['signature'] = '{{signature}}'; // kept as a placeholder in the editable text; the image is added when the letter is fixed
    if (!empty($o['offer_draft'])) {
        $html = hrd_render((string) $o['offer_draft'], $vals);
    } elseif (!empty($o['hr_template_id']) && ($t = hrd_template((int) $o['hr_template_id'])) && $t['content'] !== null) {
        $html = hrd_render(str_replace('<p>{{salary_table}}</p>', '{{salary_table}}', (string) $t['content']), $vals);
    } else {
        $tpl = ($o['template_id'] ? one("SELECT * FROM hrm_onboarding_templates WHERE id = ? AND kind = 'offer'", [$o['template_id']]) : null) ?? onb_default_template('offer');
        if (!$tpl) throw new RuntimeException('There is no offer letter template. Add one under Onboarding → Templates or the HR Document Centre.');
        $html = hrd_render(str_replace('<p>{{salary_table}}</p>', '{{salary_table}}', policy_html($tpl['body'])), $vals);
    }
    if ($withSignature && !empty($o['signatory_id']) && !str_contains($html, 'class="sig-image"') && ($sig = sig_find((int) $o['signatory_id'], false))) $html .= sig_block($sig);
    return $html;
}

/** Template text → HTML: escaped Markdown subset (policy_html), then {{placeholders}} (escaped) and {{salary_table}}. */
function onb_render(string $body, array $o): string
{
    $html = policy_html($body);
    $table = onb_salary_table($o['salary_data'] ?? []);
    $html = str_replace(['<p>{{salary_table}}</p>', '{{salary_table}}'], [$table, $table], $html);
    $map = [];
    foreach (onb_placeholders($o) as $k => $v) $map['{{' . $k . '}}'] = nl2br(e($v));
    return strtr($html, $map);
}

/** Letterhead + body, used for the offer letter, agreements and printouts. */
function onb_letter(string $title, string $bodyHtml, array $o, string $footer = ''): string
{
    $logo = setting('company_logo');
    return '<article class="onb-letter letter"><header class="onb-letterhead">'
        . '<img src="' . e($logo ? file_url($logo) : asset('images/adorableIT_dark_logo.png')) . '" alt="">'
        . '<div><strong>' . e(setting('company_name')) . '</strong><small>' . nl2br(e((string) setting('company_address', ''))) . '</small></div>'
        . '<div class="text-end small"><span class="text-muted">Ref</span> ' . e($o['offer_ref'] ?: 'DRAFT') . '</div></header>'
        . '<h2 class="onb-letter-title">' . e($title) . '</h2><div class="onb-letter-body policy-body">' . $bodyHtml . '</div>' . $footer . '</article>';
}

/** Next offer reference: OFR-2026-0001. */
function onb_next_ref(): string
{
    $prefix = 'OFR-' . date('Y') . '-';
    $max = (int) val('SELECT MAX(CAST(SUBSTRING(offer_ref, ?) AS UNSIGNED)) FROM hrm_onboardings WHERE offer_ref LIKE ?', [strlen($prefix) + 1, $prefix . '%']);
    return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

/* ───────────────────────── Secure link & one-time code ───────────────────────── */

/** New random link token (only its hash is stored). Valid until the case can no longer need it. */
function onb_issue_token(array $o): string
{
    $token = bin2hex(random_bytes(32));
    $until = max((string) $o['offer_expires'], (string) $o['joining_date'], date('Y-m-d', strtotime('+' . max(1, (int) setting('offer_valid_days', 7)) . ' days')));
    onb_save((int) $o['id'], ['token_hash' => hash('sha256', $token), 'token_expires' => date('Y-m-d 23:59:59', strtotime($until . ' +30 days'))]);
    return $token;
}

function onb_link(string $token): string
{
    return site_origin() . root_url('onboarding.php') . '?t=' . $token;
}

/** Case for a link token, or null when unknown / expired / closed. */
function onb_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $id = val("SELECT id FROM hrm_onboardings WHERE token_hash = ? AND token_expires >= NOW() AND status IN " . sql_list(ONB_CANDIDATE_VISIBLE), [hash('sha256', $token)]);
    return $id ? onb_find((int) $id) : null;
}

/** "p•••@gmail.com" */
function onb_mask_email(string $email): string
{
    return mask_email($email);
}

/* ───────────────────────── Notifications ───────────────────────── */

/** Logins (active staff) who may approve offers. */
function onb_approvers(): array
{
    $out = [];
    foreach (all("SELECT * FROM it_login WHERE status = 'active' AND email IS NOT NULL AND email <> ''") as $l) {
        $role = normalize_role($l['usertype']);
        if (in_array($role, EXTERNAL_ROLES, true)) continue;
        if ($role === 'admin' || in_array('approve_offers', permissions_for($l), true)) $out[] = $l;
    }
    return $out;
}

/** HR inbox for onboarding updates: the case owner plus the onboarding / HR e-mail. */
function onb_hr_recipients(array $o): array
{
    return array_values(array_unique(array_filter([$o['creator_email'] ?? null, setting('onboarding_hr_email') ?: setting('company_email')],
        fn($m) => $m && filter_var($m, FILTER_VALIDATE_EMAIL))));
}

function onb_notify_hr(array $o, string $subject, string $body): void
{
    $link = site_origin() . url('onboarding', ['a' => 'view', 'id' => $o['id']]);
    notify(array_merge([(int) $o['created_by']], logins_with('manage_onboarding', false)), 'onboarding', "{$o['full_name']}: $subject", mb_strimwidth($body, 0, 300, '…'), url('onboarding', ['a' => 'view', 'id' => $o['id']]), 'ri-user-received-2-line', 'info', 'onb:' . $o['id'] . ':' . md5($subject . date('YmdH')));
    foreach (onb_hr_recipients($o) as $to) {
        send_mail($to, "[Onboarding] {$o['full_name']}: $subject", "$body\n\nOpen the case: $link\n\n— " . setting('company_name') . ' HRMS');
    }
}

function onb_mail_candidate(array $o, string $subject, string $body): void
{
    if (!$o['email']) return;
    $company = setting('company_name');
    send_mail($o['email'], $subject, "Dear {$o['full_name']},\n\n$body\n\nIf you have any questions, reply to this e-mail or write to " . setting('company_email') . ".\n\nWarm regards,\n" . setting('onboarding_signatory', 'Human Resources') . "\n$company",
        setting('onboarding_hr_email') ?: setting('company_email'));
}

/* ───────────────────────── Validation (Indian statutory formats) ───────────────────────── */

/** PAN of an individual: AAAPA1234A (5 letters, 4 digits, 1 letter; 4th letter P = person). */
function onb_valid_pan(string $pan): bool
{
    return (bool) preg_match('/^[A-Z]{3}P[A-Z][0-9]{4}[A-Z]$/', $pan);
}

/** IFSC: 4-letter bank code, a zero, 6-character branch code. */
function onb_valid_ifsc(string $ifsc): bool
{
    return (bool) preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc);
}

/** Aadhaar: 12 digits, does not start with 0 or 1, valid Verhoeff check digit. */
function onb_valid_aadhaar(string $n): bool
{
    if (!preg_match('/^[2-9][0-9]{11}$/', $n)) return false;
    $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
          [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],
          [4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    $c = 0;
    foreach (array_reverse(str_split($n)) as $i => $digit) $c = $d[$c][$p[$i % 8][(int) $digit]];
    return $c === 0;
}

/** Only the last 4 digits of Aadhaar are ever stored (UIDAI guidance for employers). */
function onb_mask_aadhaar(string $n): string
{
    return 'XXXX XXXX ' . substr($n, -4);
}

function onb_digits(?string $s): string
{
    return preg_replace('/\D+/', '', (string) $s);
}

function onb_age(string $dob): int
{
    return (int) (new DateTime($dob))->diff(new DateTime('today'))->y;
}

/** Errors in the personal section. */
function onb_check_personal(array $p): array
{
    $err = [];
    foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'dob' => 'Date of birth', 'gender' => 'Gender', 'nationality' => 'Nationality', 'father_name' => 'Father\'s / spouse\'s name',
        'phone' => 'Mobile number', 'current_address' => 'Current address', 'current_city' => 'City', 'current_state' => 'State', 'current_pincode' => 'PIN code',
        'emergency_name' => 'Emergency contact name', 'emergency_relation' => 'Emergency contact relation', 'emergency_phone' => 'Emergency contact number'] as $k => $l) {
        if (trim((string) ($p[$k] ?? '')) === '') $err[] = "$l is required.";
    }
    if (!empty($p['dob'])) {
        if (!valid_date($p['dob'])) $err[] = 'Date of birth is not a valid date.';
        elseif (($age = onb_age($p['dob'])) < 18 || $age > 75) $err[] = 'Date of birth: you must be at least 18 years old.';
    }
    if (!empty($p['phone']) && strlen(onb_digits($p['phone'])) < 10) $err[] = 'Mobile number must have at least 10 digits.';
    if (!empty($p['emergency_phone']) && strlen(onb_digits($p['emergency_phone'])) < 10) $err[] = 'Emergency contact number must have at least 10 digits.';
    if (!empty($p['emergency_phone']) && !empty($p['phone']) && phone_key($p['emergency_phone']) === phone_key($p['phone'])) $err[] = 'Emergency contact number must be different from your own.';
    if (!empty($p['personal_email']) && !filter_var($p['personal_email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Personal e-mail is not valid.';
    if (!empty($p['current_pincode']) && ($p['nationality'] ?? 'Indian') === 'Indian' && !preg_match('/^[1-9][0-9]{5}$/', $p['current_pincode'])) $err[] = 'PIN code must be 6 digits.';
    if (empty($p['permanent_same'])) {
        foreach (['permanent_address' => 'Permanent address', 'permanent_city' => 'Permanent city', 'permanent_state' => 'Permanent state', 'permanent_pincode' => 'Permanent PIN code'] as $k => $l) {
            if (trim((string) ($p[$k] ?? '')) === '') $err[] = "$l is required (or tick \"same as current\").";
        }
    }
    return $err;
}

/** Errors in the education & employment section. */
function onb_check_education(array $profile): array
{
    $err = [];
    $edu = $profile['education'] ?? [];
    $year = (int) date('Y');
    if (!$edu) $err[] = 'Add at least your highest qualification.';
    foreach ($edu as $i => $r) {
        $n = $i + 1;
        if (empty($r['qualification']) || empty($r['institution']) || empty($r['year'])) $err[] = "Education row $n: qualification, institution and passing year are required.";
        elseif ((int) $r['year'] < 1960 || (int) $r['year'] > $year + 1) $err[] = "Education row $n: passing year looks wrong.";
    }
    $type = $profile['experience_type'] ?? '';
    if (!in_array($type, ['Fresher', 'Experienced'], true)) $err[] = 'Tell us whether you are a fresher or experienced.';
    if ($type === 'Experienced') {
        $jobs = $profile['employment'] ?? [];
        if (!$jobs) $err[] = 'Add your previous / current employer.';
        foreach ($jobs as $i => $r) {
            $n = $i + 1;
            if (empty($r['employer']) || empty($r['designation']) || empty($r['from'])) { $err[] = "Employment row $n: employer, designation and start date are required."; continue; }
            if (!valid_date($r['from']) || (!empty($r['to']) && !valid_date($r['to']))) $err[] = "Employment row $n: dates are not valid.";
            elseif (!empty($r['to']) && $r['to'] < $r['from']) $err[] = "Employment row $n: the end date is before the start date.";
            elseif ($r['from'] > date('Y-m-d')) $err[] = "Employment row $n: the start date is in the future.";
        }
    }
    return $err;
}

/** Errors in the bank & statutory section. */
function onb_check_bank(array $profile): array
{
    $b = $profile['bank'] ?? [];
    $s = $profile['statutory'] ?? [];
    $err = [];
    foreach (['account_holder' => 'Account holder name', 'bank_name' => 'Bank name', 'account_number' => 'Account number', 'ifsc' => 'IFSC'] as $k => $l) {
        if (trim((string) ($b[$k] ?? '')) === '') $err[] = "$l is required.";
    }
    if (!empty($b['account_number']) && !preg_match('/^[0-9]{9,18}$/', $b['account_number'])) $err[] = 'Account number must be 9 to 18 digits.';
    if (!empty($b['ifsc']) && !onb_valid_ifsc($b['ifsc'])) $err[] = 'IFSC must look like HDFC0001234 (11 characters, 5th is zero).';
    if (empty($s['pan'])) $err[] = 'PAN is required.';
    elseif (!onb_valid_pan($s['pan'])) $err[] = 'PAN must be an individual PAN like ABCPE1234F.';
    if (empty($s['aadhaar_last4'])) $err[] = 'Aadhaar number is required.';
    if (!in_array($s['prev_pf_member'] ?? '', ['Yes', 'No'], true)) $err[] = 'Answer whether you were a member of a provident fund before.';
    if (($s['prev_pf_member'] ?? '') === 'Yes' && !preg_match('/^[0-9]{12}$/', (string) ($s['uan'] ?? ''))) $err[] = 'Enter your 12-digit UAN (from your previous employer / payslip).';
    if (!empty($s['uan']) && !preg_match('/^[0-9]{12}$/', $s['uan'])) $err[] = 'UAN must be 12 digits.';
    if (!empty($s['esi_number']) && !preg_match('/^[0-9]{10}([0-9]{7})?$/', $s['esi_number'])) $err[] = 'ESI IP number must be 10 (or 17) digits.';
    if (empty($s['nominee_name']) || empty($s['nominee_relation'])) $err[] = 'Nominee name and relation are required (PF / gratuity nomination).';
    if (!empty($s['nominee_dob']) && !valid_date($s['nominee_dob'])) $err[] = 'Nominee date of birth is not valid.';
    if (empty($s['tax_regime']) || !in_array($s['tax_regime'], ['New', 'Old'], true)) $err[] = 'Choose your income-tax regime.';
    return $err;
}

/* ───────────────────────── Documents & e-signatures ───────────────────────── */

/** Latest upload per document type (replaced uploads are kept for the audit trail but ignored here). */
function onb_documents(int $id): array
{
    $out = [];
    foreach (all("SELECT d.*, l.username reviewer FROM hrm_onboarding_documents d LEFT JOIN it_login l ON l.id = d.reviewed_by
                  WHERE d.onboarding_id = ? AND d.status <> 'Replaced' ORDER BY d.id", [$id]) as $d) {
        $out[$d['doc_type']] = $d;
    }
    return $out;
}

function onb_doc_required(array $type, array $o): bool
{
    return $type['required'] === 'yes' || ($type['required'] === 'experienced' && ($o['profile_data']['experience_type'] ?? '') === 'Experienced');
}

/** [missing required types, rejected uploads] for the candidate's documents step. */
function onb_document_gaps(array $o): array
{
    $docs = onb_documents((int) $o['id']);
    $missing = $rejected = [];
    foreach (onb_document_types() as $key => $t) {
        $d = $docs[$key] ?? null;
        if ($d && $d['status'] === 'Rejected') $rejected[$key] = $t['label'];
        elseif (!$d && onb_doc_required($t, $o)) $missing[$key] = $t['label'];
    }
    return [$missing, $rejected];
}

/**
 * Documents the candidate e-signs: the offer (at acceptance), the employment agreement and NDA (active default
 * templates) and every published HR policy that needs acknowledgement and applies to their department.
 * Returns key => [title, kind, version, body (template text or policy), template_id|policy_id].
 */
function onb_sign_docs(array $o): array
{
    $docs = ['offer' => ['title' => 'Offer letter', 'kind' => 'offer', 'version' => 'r' . (int) $o['revision'], 'ref' => null]];
    foreach (['agreement', 'nda'] as $kind) {
        if ($t = onb_default_template($kind)) $docs[$kind] = ['title' => $t['name'], 'kind' => $kind, 'version' => (string) $t['version'], 'ref' => (int) $t['id'], 'body' => $t['body']];
    }
    foreach (all("SELECT id, title, version, summary, content FROM hrm_policies WHERE status = 'Published' AND requires_ack = 1 AND (department_id IS NULL OR department_id <=> ?) ORDER BY title", [$o['department_id']]) as $p) {
        $docs['policy_' . $p['id']] = ['title' => $p['title'], 'kind' => 'policy', 'version' => $p['version'], 'ref' => (int) $p['id'], 'body' => (string) $p['content'], 'summary' => $p['summary']];
    }
    return $docs;
}

/** HTML of a document to be signed, exactly as the candidate sees it (hashed into the signature record). */
function onb_sign_doc_html(array $doc, array $o): string
{
    if ($doc['kind'] === 'offer') return (string) $o['offer_html'];
    if ($doc['kind'] === 'policy') return policy_html($doc['body'] ?: ($doc['summary'] ?? ''));
    return onb_render($doc['body'], $o);
}

function onb_signatures(int $id): array
{
    return array_column(all('SELECT * FROM hrm_onboarding_signatures WHERE onboarding_id = ? ORDER BY id', [$id]), null, 'doc_key');
}

/** Documents still to be signed (key => doc). A signature on an older version of a document does not count. */
function onb_unsigned(array $o): array
{
    $signed = onb_signatures((int) $o['id']);
    $out = [];
    foreach (onb_sign_docs($o) as $k => $d) {
        if (!isset($signed[$k]) || (string) $signed[$k]['version'] !== (string) $d['version']) $out[$k] = $d;
    }
    return $out;
}

/**
 * Stores a drawn / typed signature (PNG data URL from the signature pad). Returns the stored path.
 * Validates that it really is a small PNG, never trusting the browser.
 */
function onb_store_signature(string $dataUrl, int $onbId, string $key, string $folder = 'signatures', string $prefix = 'o'): string
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) throw new RuntimeException('Please sign in the signature box.');
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 200 || strlen($bin) > 400 * 1024 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('The signature could not be read. Please sign again.');
    $size = @getimagesizefromstring($bin);
    if (!$size || $size[0] < 100 || $size[1] < 30 || $size[0] > 2400 || $size[1] > 1200) throw new RuntimeException('The signature could not be read. Please sign again.');
    $dir = STORAGE_DIR . '/uploads/' . $folder;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $prefix . $onbId . '_' . preg_replace('/[^a-z0-9_]/', '', $key) . '_' . bin2hex(random_bytes(6)) . '.png';
    if (file_put_contents("$dir/$name", $bin) === false) throw new RuntimeException('The signature could not be saved. Please try again.');
    return "$folder/$name";
}

/** Signature record (who, what, when, where, and the SHA-256 of the exact document text). */
function onb_record_signature(array $o, string $key, array $doc, string $html, string $signedName, string $sigPath): void
{
    q('DELETE FROM hrm_onboarding_signatures WHERE onboarding_id = ? AND doc_key = ?', [$o['id'], $key]);
    insert('hrm_onboarding_signatures', ['onboarding_id' => $o['id'], 'doc_key' => $key, 'title' => mb_substr($doc['title'], 0, 190), 'kind' => $doc['kind'],
        'ref_id' => $doc['ref'] ?? null, 'version' => (string) $doc['version'], 'content' => $html, 'content_hash' => hash('sha256', $html),
        'signed_name' => mb_substr($signedName, 0, 150), 'signature_file' => $sigPath, 'ip' => client_ip(),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'signed_at' => date('Y-m-d H:i:s')]);
}

/** The typed name must match the candidate's name (intent to sign); spacing and case are ignored. */
function onb_name_matches(string $typed, array $o): bool
{
    $norm = fn($s) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $s)));
    $p = $o['profile_data']['personal'] ?? [];
    $legal = trim(implode(' ', array_filter([$p['first_name'] ?? null, $p['middle_name'] ?? null, $p['last_name'] ?? null])));
    $short = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    return $norm($typed) !== '' && in_array($norm($typed), array_filter([$norm($o['full_name']), $norm($legal), $norm($short)]), true);
}

/* ───────────────────────── Progress ───────────────────────── */

/** Candidate steps with done flag and open issues. */
function onb_step_status(array $o): array
{
    $pr = $o['profile_data'];
    [$missing, $rejected] = onb_document_gaps($o);
    $unsigned = onb_unsigned($o);
    $issues = [
        'offer'     => $o['accepted_at'] ? [] : ['Accept the offer.'],
        'personal'  => isset($pr['personal']) ? onb_check_personal($pr['personal']) : ['Fill in your personal information.'],
        'education' => isset($pr['education']) ? onb_check_education($pr) : ['Add your education and employment history.'],
        'bank'      => isset($pr['bank']) ? onb_check_bank($pr) : ['Add your bank and statutory details.'],
        'documents' => array_merge(array_map(fn($l) => "Upload: $l", array_values($missing)), array_map(fn($l) => "Upload again (rejected): $l", array_values($rejected))),
        'esign'     => array_map(fn($d) => 'Sign: ' . $d['title'], array_values($unsigned)),
        'submit'    => $o['form_submitted_at'] && $o['status'] !== 'Correction Requested' ? [] : ['Review and submit your onboarding form.'],
    ];
    $out = [];
    foreach (ONB_STEPS as $k => [$label, $icon]) $out[$k] = ['label' => $label, 'icon' => $icon, 'issues' => $issues[$k], 'done' => !$issues[$k]];
    return $out;
}

/** Percent of candidate steps done (0 before the offer is accepted). */
function onb_percent(array $o): int
{
    if (!$o['accepted_at']) return 0;
    $s = onb_step_status($o);
    return (int) round(count(array_filter($s, fn($x) => $x['done'])) / count($s) * 100);
}

/** Compliance: automatic checks + HR's manual items. Returns key => [label, done, auto, required, by, at]. */
function onb_compliance(array $o): array
{
    $docs = onb_documents((int) $o['id']);
    $types = onb_document_types();
    $reqTypes = array_filter($types, fn($t) => onb_doc_required($t, $o));
    $allVerified = $reqTypes && !array_filter(array_keys($reqTypes), fn($k) => ($docs[$k]['status'] ?? '') !== 'Verified')
        && !array_filter($docs, fn($d) => $d['status'] === 'Pending');
    $unsigned = onb_unsigned($o);
    $pr = $o['profile_data'];
    $items = [
        '_documents' => ['label' => 'All required documents verified', 'done' => (bool) $allVerified, 'auto' => true, 'required' => true],
        '_signed'    => ['label' => 'Offer, agreement, NDA and policies e-signed', 'done' => !$unsigned, 'auto' => true, 'required' => true],
        '_declared'  => ['label' => 'Candidate declarations confirmed (accuracy, background check consent, data processing)', 'done' => (bool) $o['declaration_at'], 'auto' => true, 'required' => true],
        '_statutory' => ['label' => 'Bank, PAN, Aadhaar and PF details complete and valid', 'done' => isset($pr['bank']) && !onb_check_bank($pr), 'auto' => true, 'required' => true],
    ];
    foreach (onb_compliance_items() as $k => $t) {
        $c = $o['compliance_data'][$k] ?? null;
        $items[$k] = ['label' => $t['label'], 'hint' => $t['hint'], 'done' => !empty($c['done']), 'auto' => false, 'required' => $t['required'] === 'yes',
            'by' => $c['by'] ?? null, 'at' => $c['at'] ?? null, 'na' => !empty($c['na'])];
    }
    return $items;
}

/** Why HR final approval is not possible yet ([] when it is). */
function onb_approval_blockers(array $o): array
{
    $out = [];
    if ($o['status'] !== 'Submitted') $out[] = 'The candidate has not submitted the onboarding form' . ($o['status'] === 'Correction Requested' ? ' again after the corrections.' : '.');
    foreach (onb_compliance($o) as $c) {
        if ($c['required'] && !$c['done'] && empty($c['na'])) $out[] = $c['label'];
    }
    return $out;
}

/** The 18 workflow steps (3 phases) with done / current flags, for the case page stepper. */
function onb_workflow(array $o): array
{
    $st = $o['accepted_at'] ? onb_step_status($o) : array_map(fn() => ['done' => false], ONB_STEPS);
    $comp = onb_compliance($o);
    $docsDone = $comp['_documents']['done'];
    $manualDone = !array_filter($comp, fn($c) => !$c['auto'] && $c['required'] && !$c['done'] && empty($c['na']));
    $phases = [
        'Recruitment & offer' => [
            'Final selection' => true,
            'Offer letter' => (bool) $o['offer_ref'] && $o['ctc_annual'] !== null,
            'Offer approval' => (bool) $o['approved_at'],
            'Offer e-mailed' => (bool) $o['sent_at'],
            'Candidate verified' => (bool) $o['email_verified_at'],
            'Offer accepted' => (bool) $o['accepted_at'],
        ],
        'Information & documents' => [
            'Personal information' => $st['personal']['done'], 'Education & employment' => $st['education']['done'],
            'Bank & statutory' => $st['bank']['done'], 'Documents' => $st['documents']['done'],
            'E-signature' => $st['esign']['done'], 'Final submission' => (bool) $o['form_submitted_at'] && $o['status'] !== 'Correction Requested',
        ],
        'Verification & activation' => [
            'Document verification' => $docsDone, 'Compliance check' => $manualDone && $docsDone,
            'HR final approval' => (bool) $o['hr_approved_at'], 'Employee ID' => (bool) $o['employee_id'],
            'Profile activation' => (bool) $o['activated_at'], 'Joining & induction' => $o['status'] === 'Joined',
        ],
    ];
    $foundCurrent = false;
    $out = [];
    foreach ($phases as $phase => $steps) {
        foreach ($steps as $label => $done) {
            $current = !$done && !$foundCurrent && !in_array($o['status'], ['Declined', 'Withdrawn'], true);
            if ($current) $foundCurrent = true;
            $out[$phase][$label] = ['done' => (bool) $done, 'current' => $current];
        }
    }
    return $out;
}

/** Counts for the menu badge / bell: offers to approve (approvers) and cases needing HR action. */
function onb_counts(): array
{
    $c = ['onboarding_approve' => 0, 'onboarding_review' => 0];
    if (!user() || is_external() || !plan_allows('manage_onboarding')) return $c;
    if (can('approve_offers')) {
        $c['onboarding_approve'] = (int) val("SELECT COUNT(*) FROM hrm_onboardings WHERE status = 'Pending Approval' AND (created_by IS NULL OR created_by <> ? OR ?)",
            [user()['id'], is_super_admin() ? 1 : 0]);
    }
    if (can('manage_onboarding')) {
        $c['onboarding_review'] = (int) val("SELECT COUNT(*) FROM hrm_onboardings WHERE status IN ('Submitted','Changes Requested','Ready to Join') OR (status = 'Activated' AND joining_date <= CURDATE())");
    }
    return $c;
}

/* ───────────────────────── Activation & joining ───────────────────────── */

/**
 * Employee ID generation + profile activation. Creates the employee from the verified onboarding data, the salary
 * structure from the offer, starts probation, records policy acknowledgements, copies documents into the employee
 * file and turns the candidate's portal login into an employee login (same e-mail and password).
 * Returns the new employee id; throws RuntimeException with a user-facing message.
 */
function onb_activate(array $o): int
{
    if ($o['employee_id']) return (int) $o['employee_id'];
    if ($err = seat_limit_error()) throw new RuntimeException($err);
    $pr = $o['profile_data'];
    $p = $pr['personal'] ?? [];
    $b = $pr['bank'] ?? [];
    $s = $pr['statutory'] ?? [];
    if (val('SELECT id FROM hrm_employees WHERE email = ?', [$o['email']])) {
        throw new RuntimeException('An employee with the e-mail ' . $o['email'] . ' already exists. Change that employee\'s e-mail (or link the record) before activating.');
    }
    $docs = onb_documents((int) $o['id']);
    $photo = null;
    if (!empty($docs['photo']) && preg_match('/\.(jpe?g|png)$/i', $docs['photo']['file']) && is_file(STORAGE_DIR . '/uploads/' . $docs['photo']['file'])) {
        $photo = 'photos/' . basename($docs['photo']['file']);
        if (!is_dir(STORAGE_DIR . '/uploads/photos')) mkdir(STORAGE_DIR . '/uploads/photos', 0755, true);
        if (!copy(STORAGE_DIR . '/uploads/' . $docs['photo']['file'], STORAGE_DIR . '/uploads/' . $photo)) $photo = null;
    }
    $probation = setting('onboarding_probation', '1') === '1';
    $same = !empty($p['permanent_same']);
    $address = trim((string) ($p['current_address'] ?? '')) . ($same ? '' : "\nPermanent: " . trim(implode(', ', array_filter([$p['permanent_address'] ?? '', $p['permanent_city'] ?? '', $p['permanent_state'] ?? '', $p['permanent_pincode'] ?? '']))));

    // One activation at a time issues an employee code: parallel activations wait (up to 20 s) instead of computing the same number.
    if ((int) val("SELECT GET_LOCK('hrms_emp_code', 20)") !== 1) throw new RuntimeException('Another activation is in progress. Please try again in a moment.');
    // Re-checked under the lock: a double click or retried request returns the employee created by the first one.
    if ($done = (int) val('SELECT employee_id FROM hrm_onboardings WHERE id = ?', [$o['id']])) { val("SELECT RELEASE_LOCK('hrms_emp_code')"); return $done; }
    if (val('SELECT id FROM hrm_employees WHERE email = ?', [$o['email']])) { val("SELECT RELEASE_LOCK('hrms_emp_code')"); throw new RuntimeException('An employee with the e-mail ' . $o['email'] . ' already exists.'); }
    db()->beginTransaction();
    try {
        // The employee code is generated inside the transaction and retried on the (unlikely) clash with a parallel activation.
        $empId = null;
        for ($try = 0; $try < 3 && !$empId; $try++) {
            $code = next_emp_code();
            try {
                $empId = insert('hrm_employees', [
                    'emp_code' => $code, 'first_name' => mb_substr($p['first_name'] ?? $o['full_name'], 0, 80), 'last_name' => mb_substr(trim(($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? '')), 0, 80) ?: null,
                    'email' => $o['email'], 'phone' => $p['phone'] ?? $o['phone'], 'gender' => in_array($p['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $p['gender'] : null,
                    'dob' => valid_date($p['dob'] ?? null), 'marital_status' => in_array($p['marital_status'] ?? '', ['Single', 'Married', 'Other'], true) ? $p['marital_status'] : null,
                    'blood_group' => $p['blood_group'] ?? null, 'address' => mb_substr($address, 0, 1000) ?: null, 'city' => $p['current_city'] ?? null, 'state' => $p['current_state'] ?? null,
                    'pincode' => $p['current_pincode'] ?? null, 'department_id' => $o['department_id'], 'designation_id' => $o['designation_id'], 'manager_id' => $o['manager_id'],
                    'employment_type' => $o['employment_type'] ?: 'Full-time', 'work_location' => $o['work_location'], 'join_date' => $o['joining_date'] ?: date('Y-m-d'),
                    'status' => $probation ? 'On Probation' : 'Active', 'ctc_annual' => $o['ctc_annual'], 'pan_no' => $s['pan'] ?? null,
                    'aadhaar_no' => !empty($s['aadhaar_last4']) ? 'XXXX XXXX ' . $s['aadhaar_last4'] : null, 'uan_no' => $s['uan'] ?? null,
                    'bank_name' => $b['bank_name'] ?? null, 'bank_account' => $b['account_number'] ?? null, 'bank_ifsc' => $b['ifsc'] ?? null,
                    'emergency_name' => trim(($p['emergency_name'] ?? '') . (!empty($p['emergency_relation']) ? ' (' . $p['emergency_relation'] . ')' : '')) ?: null,
                    'emergency_phone' => $p['emergency_phone'] ?? null, 'photo' => $photo, 'probation_track' => $o['probation_track'] ?: null,
                    'probation_status' => $probation ? null : 'Confirmed', 'candidate_id' => $o['candidate_id'] ?: null,
                ]);
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000' || $try === 2) throw $ex; // 23000 = duplicate employee code
            }
        }
        $emp = one('SELECT * FROM hrm_employees WHERE id = ?', [$empId]);
        if ($probation) probation_start($emp);

        // Payroll: salary structure from the offer.
        $sal = $o['salary_data'];
        if ($sal) {
            q('REPLACE INTO hrm_salary_structures (employee_id, basic, hra, conveyance, medical, special_allowance, other_allowance, pf_enabled, professional_tax, tds, other_deduction)
               VALUES (?, ?, ?, 0, 0, ?, 0, ?, ?, 0, 0)', [$empId, $sal['basic'], $sal['hra'], $sal['special_allowance'], !empty($sal['pf']) ? 1 : 0, (float) setting('default_professional_tax', 200)]);
        }

        // Documents → employee file (verified uploads only; files stay where they are).
        $types = onb_document_types();
        foreach ($docs as $key => $d) {
            if ($d['status'] !== 'Verified') continue;
            insert('hrm_employee_documents', ['employee_id' => $empId, 'title' => mb_substr(($types[$key]['label'] ?? $d['title']) . ' (onboarding)', 0, 150), 'file' => $d['file'], 'uploaded_by' => user()['id'] ?? null]);
        }

        // Policies signed during onboarding count as acknowledged (same version).
        foreach (onb_signatures((int) $o['id']) as $sig) {
            if ($sig['kind'] === 'policy' && $sig['ref_id']) {
                q('INSERT IGNORE INTO hrm_policy_acks (policy_id, employee_id, version, acknowledged_at, ip) VALUES (?, ?, ?, ?, ?)', [$sig['ref_id'], $empId, $sig['version'], $sig['signed_at'], $sig['ip']]);
            }
        }

        // Portal access: the candidate keeps their login, which becomes an employee login with the role chosen on the offer.
        $role = normalize_role($o['login_role'] ?: 'employee');
        if (in_array($role, array_merge(EXTERNAL_ROLES, ['admin']), true)) $role = 'employee';
        $now = date('Y-m-d H:i:s');
        if ($o['login_id'] && one('SELECT id FROM it_login WHERE id = ?', [$o['login_id']])) {
            q("UPDATE it_login SET usertype = ?, employee_id = ?, permissions = NULL, status = 'active', updated_at = ? WHERE id = ?", [$role, $empId, $now, $o['login_id']]);
        } elseif ($same = one('SELECT * FROM it_login WHERE email = ? OR username = ? ORDER BY id LIMIT 1', [$o['email'], $o['email']])) {
            // The person already has an account with this personal e-mail (e.g. a careers profile): link it, never create a second one.
            // An account of another employee, a client or a vendor is never taken over.
            if ($same['employee_id'] || in_array(normalize_role($same['usertype']), ['client', 'vendor', 'admin'], true)) {
                throw new RuntimeException('Another HRMS account already uses ' . $o['email'] . '. Change that account\x27s e-mail or the personal e-mail on this case before activating.');
            }
            q("UPDATE it_login SET usertype = ?, employee_id = ?, permissions = NULL, status = 'active', updated_at = ? WHERE id = ?", [$role, $empId, $now, $same['id']]);
        } else {
            // New account with an unusable random password: the employee sets their own through the one-time link in the welcome e-mail.
            $loginId = insert('it_login', ['username' => $o['email'], 'email' => $o['email'], 'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'usertype' => $role,
                'employee_id' => $empId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }

        if ($o['candidate_id']) q('UPDATE hrm_candidates SET employee_id = ? WHERE id = ?', [$empId, $o['candidate_id']]);
        // From now on they sign in as an employee; the e-mailed link is no longer needed.
        onb_save((int) $o['id'], ['employee_id' => $empId, 'activated_at' => $now, 'activated_by' => user()['id'] ?? null, 'status' => 'Activated', 'token_hash' => null, 'token_expires' => null]);
        onb_log((int) $o['id'], 'employee_created', "Employee ID $code · " . ROLES[$role] . ' login', 'system');
        hrd_event(['employee_id' => $empId], 'emp_id', "$code for onboarding {$o['offer_ref']} (candidate #" . (int) $o['candidate_id'] . ')');
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        val("SELECT RELEASE_LOCK('hrms_emp_code')");
        throw $ex;
    }
    val("SELECT RELEASE_LOCK('hrms_emp_code')");
    log_activity('created', 'Employee', $empId, "Onboarding {$o['offer_ref']} → $code");
    // Credentials e-mail to the personal e-mail of the case (never a password). A failure is recorded and can be resent by HR;
    // it never undoes the activation, so a retry can't create a second employee or login.
    try {
        onb_send_welcome(onb_find((int) $o['id']));
    } catch (RuntimeException $ex) {
        q("UPDATE hrm_onboardings SET welcome_status = 'Pending', welcome_error = ? WHERE id = ?", [mb_substr($ex->getMessage(), 0, 255), $o['id']]);
        onb_log((int) $o['id'], 'welcome_email', 'Not sent: ' . $ex->getMessage(), 'system');
    }
    return $empId;
}

/** Initial tasks and training enrolments for a joiner. Returns [tasks created, trainings enrolled]. */
function onb_assign_induction(int $empId, string $joinDate, array $trainingIds, bool $withTasks = true): array
{
    $tasks = 0;
    foreach ($withTasks ? onb_task_list() : [] as [$title, $days]) {
        if (val("SELECT id FROM hrm_tasks WHERE assignee_id = ? AND title = ? AND status <> 'Done'", [$empId, $title])) continue;
        insert('hrm_tasks', ['title' => $title, 'assignee_id' => $empId, 'due_date' => date('Y-m-d', strtotime("$joinDate +$days days")), 'priority' => 'High', 'status' => 'To Do',
            'description' => 'Onboarding task, assigned automatically when joining was confirmed.']);
        $tasks++;
    }
    $enrolled = 0;
    foreach (array_unique(array_map('intval', $trainingIds)) as $tid) {
        if ($tid && val('SELECT id FROM hrm_trainings WHERE id = ?', [$tid])) {
            q("INSERT IGNORE INTO hrm_training_participants (training_id, employee_id, status) VALUES (?, ?, 'Enrolled')", [$tid, $empId]);
            $enrolled++;
        }
    }
    return [$tasks, $enrolled];
}
