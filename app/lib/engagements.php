<?php
/**
 * Client onboarding: one common client portal, two service workflows.
 *
 *   Client registration (verified e-mail) → select service → dynamic form (HR: recruitment requirement, IT: service enquiry)
 *   → review / missing information → commercial proposal + documents (HR: NDA, recruitment agreement · IT: NDA, work agreement, SOP)
 *   → client e-signs (authorised signatory, e-mail OTP at signing) → advance payment → internal authorisation
 *   → activation (HR: vacancy · IT: project) → execution & reporting → delivery / joining → closure.
 *
 * An "engagement" is one requirement (HR) or project (IT). Activation never happens on submission alone: it needs the
 * proposal accepted, every required document signed in its current version, the payment condition met and an internal
 * authorisation (eng_gates()). Company-level documents (NDA, master agreements) are signed once per client and re-signed
 * only when the template changes; the SOP and proposal belong to the engagement. Signed versions are never overwritten.
 */

const ENG_SERVICES = [
    'hr' => ['label' => 'HR Services', 'icon' => 'ri-user-search-line', 'form' => 'Recruitment requirement', 'output' => 'Vacancy', 'blurb' => 'Hire through our recruitment team: share the role, we source, screen and deliver candidates up to joining.'],
    'it' => ['label' => 'IT Services', 'icon' => 'ri-code-box-line', 'form' => 'Service enquiry', 'output' => 'Project', 'blurb' => 'Software development, websites, apps, cloud and support: tell us what you need and we plan, build and deliver.'],
];

const ENG_STATUSES = ['Draft', 'Submitted', 'Info Requested', 'Proposal Sent', 'Awaiting Payment', 'Awaiting Authorization', 'Active', 'Delivered', 'Closed', 'Declined', 'Cancelled'];
const ENG_PROPOSAL_STAGE = ['Proposal Sent', 'Awaiting Payment', 'Awaiting Authorization'];
const ENG_OPEN = ['Submitted', 'Info Requested', 'Proposal Sent', 'Awaiting Payment', 'Awaiting Authorization', 'Active', 'Delivered'];

/** Six-step tracker shown to clients and staff. */
const ENG_PHASES = [
    'request'   => ['Request', ['Draft', 'Submitted', 'Info Requested']],
    'approvals' => ['Commercial & document approvals', ['Proposal Sent']],
    'payment'   => ['Payment & authorisation', ['Awaiting Payment', 'Awaiting Authorization']],
    'execution' => ['Execution & reporting', ['Active']],
    'delivery'  => ['Delivery / joining', ['Delivered']],
    'closure'   => ['Closure', ['Closed']],
];

/** Document kinds. scope: company = signed once per client (per service), engagement = per requirement / project. */
const ENG_DOC_KINDS = [
    'nda'          => ['label' => 'Non-disclosure agreement', 'scope' => 'company', 'template' => 'client_nda'],
    'hr_agreement' => ['label' => 'Recruitment agreement', 'scope' => 'company', 'template' => 'client_hr_agreement'],
    'it_agreement' => ['label' => 'Work agreement (master services)', 'scope' => 'company', 'template' => 'client_it_agreement'],
    'sop'          => ['label' => 'Statement of work (SOP)', 'scope' => 'engagement', 'template' => 'client_sop'],
    'proposal'     => ['label' => 'Commercial proposal', 'scope' => 'engagement', 'template' => 'client_proposal'],
    'deliverable'  => ['label' => 'Deliverable', 'scope' => 'engagement', 'template' => null],
    'file'         => ['label' => 'Shared file', 'scope' => 'engagement', 'template' => null],
];
/** Signed documents each service needs before activation (in signing order). */
const ENG_REQUIRED_DOCS = ['hr' => ['nda', 'hr_agreement', 'proposal'], 'it' => ['nda', 'it_agreement', 'sop', 'proposal']];
const ENG_TEMPLATE_KINDS = ['client_nda' => 'Client NDA', 'client_hr_agreement' => 'Recruitment agreement', 'client_it_agreement' => 'Work agreement (IT)',
    'client_sop' => 'Statement of work (SOP)', 'client_proposal' => 'Commercial proposal'];

const ENG_FEE_MODELS = [
    'hr' => ['percent' => '% of annual CTC per hire', 'fixed' => 'Fixed fee per hire', 'retainer' => 'Retainer + success fee'],
    'it' => ['fixed' => 'Fixed price', 'milestone' => 'Milestone based', 'tm' => 'Time & material (per hour)', 'dedicated' => 'Dedicated resource (per month)'],
];

/**
 * Dynamic forms: section => [field => definition]. Only fields whose show_if matches are displayed, validated and stored.
 * type: text | textarea | number | select | checks (multi) | date | yesno | file
 */
const ENG_FORMS = [
    'hr' => [
        'The position' => [
            'position_title'  => ['label' => 'Position / job title', 'required' => true, 'col' => 'col-md-6', 'placeholder' => 'e.g. Senior PHP Developer'],
            'department'      => ['label' => 'Department / function', 'col' => 'col-md-6', 'placeholder' => 'e.g. Engineering'],
            'openings'        => ['label' => 'Number of openings', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 500, 'col' => 'col-md-3'],
            'employment_type' => ['label' => 'Employment type', 'type' => 'select', 'required' => true, 'options' => ['Full-time', 'Contract', 'Contract-to-hire', 'Part-time', 'Internship'], 'col' => 'col-md-3'],
            'contract_months' => ['label' => 'Contract duration (months)', 'type' => 'number', 'min' => 1, 'max' => 60, 'col' => 'col-md-3', 'show_if' => ['employment_type', ['Contract', 'Contract-to-hire']]],
            'work_mode'       => ['label' => 'Work mode', 'type' => 'select', 'required' => true, 'options' => ['On-site', 'Hybrid', 'Remote'], 'col' => 'col-md-3'],
            'locations'       => ['label' => 'Job location(s)', 'required' => true, 'col' => 'col-md-6', 'placeholder' => 'e.g. Noida, Gurugram', 'show_if' => ['work_mode', ['On-site', 'Hybrid']]],
            'reason'          => ['label' => 'Reason for hiring', 'type' => 'select', 'options' => ['New position', 'Replacement', 'Project requirement', 'Expansion'], 'col' => 'col-md-6'],
        ],
        'Candidate profile' => [
            'exp_min'          => ['label' => 'Experience from (years)', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 40, 'step' => '0.5', 'col' => 'col-md-3'],
            'exp_max'          => ['label' => 'Experience to (years)', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 45, 'step' => '0.5', 'col' => 'col-md-3'],
            'budget_min'       => ['label' => 'Budget from (₹ LPA)', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.1', 'col' => 'col-md-3'],
            'budget_max'       => ['label' => 'Budget to (₹ LPA)', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.1', 'col' => 'col-md-3'],
            'skills_must'      => ['label' => 'Must-have skills', 'type' => 'textarea', 'rows' => 2, 'required' => true, 'col' => 'col-md-6', 'placeholder' => 'Comma separated'],
            'skills_nice'      => ['label' => 'Good-to-have skills', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-md-6'],
            'qualification'    => ['label' => 'Qualification', 'col' => 'col-md-6', 'placeholder' => 'e.g. B.Tech / MCA'],
            'notice_period'    => ['label' => 'Acceptable notice period', 'type' => 'select', 'options' => ['Immediate', 'Up to 15 days', 'Up to 30 days', 'Up to 60 days', 'Up to 90 days', 'Any'], 'col' => 'col-md-6'],
            'job_description'  => ['label' => 'Job description / responsibilities', 'type' => 'textarea', 'rows' => 6, 'required' => true, 'col' => 'col-12'],
            'jd_file'          => ['label' => 'JD document (optional)', 'type' => 'file', 'col' => 'col-md-6', 'help' => 'PDF or Word, up to 5 MB.'],
        ],
        'Hiring process' => [
            'interview_rounds' => ['label' => 'Interview rounds', 'type' => 'number', 'min' => 1, 'max' => 8, 'col' => 'col-md-3'],
            'interview_mode'   => ['label' => 'Interview mode', 'type' => 'select', 'options' => ['Video', 'In person', 'Both'], 'col' => 'col-md-3'],
            'target_date'      => ['label' => 'Target joining date', 'type' => 'date', 'required' => true, 'col' => 'col-md-3'],
            'confidential'     => ['label' => 'Confidential hiring?', 'type' => 'yesno', 'col' => 'col-md-3', 'help' => 'Yes = not advertised publicly.'],
            'hiring_contact'   => ['label' => 'Hiring manager (name & e-mail)', 'col' => 'col-md-6'],
            'notes'            => ['label' => 'Anything else we should know?', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-md-6'],
        ],
    ],
    'it' => [
        'What you need' => [
            'service_type'   => ['label' => 'Service', 'type' => 'select', 'required' => true, 'col' => 'col-md-6',
                'options' => ['Web application', 'Mobile app', 'Website', 'E-commerce', 'ERP / CRM', 'Cloud & DevOps', 'UI / UX design', 'Maintenance & support', 'Dedicated team / IT staffing', 'Other']],
            'project_title'  => ['label' => 'Project name', 'required' => true, 'col' => 'col-md-6', 'placeholder' => 'e.g. Customer self-service portal'],
            'description'    => ['label' => 'Describe the project and the problem it solves', 'type' => 'textarea', 'rows' => 5, 'required' => true, 'col' => 'col-12'],
            'platforms'      => ['label' => 'Platforms', 'type' => 'checks', 'options' => ['Web', 'Android', 'iOS', 'Desktop', 'API only'], 'col' => 'col-md-6',
                'show_if' => ['service_type', ['Web application', 'Mobile app', 'E-commerce', 'ERP / CRM', 'Other']]],
            'pages'          => ['label' => 'Approx. number of pages', 'type' => 'number', 'min' => 1, 'max' => 1000, 'col' => 'col-md-3', 'show_if' => ['service_type', ['Website', 'E-commerce']]],
            'team_size'      => ['label' => 'Team size needed', 'type' => 'number', 'min' => 1, 'max' => 200, 'col' => 'col-md-3', 'show_if' => ['service_type', ['Dedicated team / IT staffing']]],
            'roles_needed'   => ['label' => 'Roles needed', 'col' => 'col-md-6', 'placeholder' => 'e.g. 2 React developers, 1 QA', 'show_if' => ['service_type', ['Dedicated team / IT staffing']]],
            'cloud'          => ['label' => 'Cloud provider', 'type' => 'select', 'options' => ['AWS', 'Azure', 'Google Cloud', 'Other / not sure'], 'col' => 'col-md-3', 'show_if' => ['service_type', ['Cloud & DevOps']]],
        ],
        'Scope & current system' => [
            'features'       => ['label' => 'Key features / scope', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-12', 'placeholder' => 'One per line'],
            'has_existing'   => ['label' => 'Is there an existing system?', 'type' => 'yesno', 'required' => true, 'col' => 'col-md-4'],
            'existing_desc'  => ['label' => 'Existing system: technology, hosting, access', 'type' => 'textarea', 'rows' => 2, 'required' => true, 'col' => 'col-md-8', 'show_if' => ['has_existing', ['Yes']]],
            'tech_pref'      => ['label' => 'Technology preferences', 'col' => 'col-md-6', 'placeholder' => 'e.g. Laravel, React, Flutter (or "your recommendation")'],
            'integrations'   => ['label' => 'Integrations', 'col' => 'col-md-6', 'placeholder' => 'Payment gateway, SMS, ERP, SSO…'],
            'attachment'     => ['label' => 'Requirement document / wireframes (optional)', 'type' => 'file', 'col' => 'col-md-6', 'help' => 'PDF, Word, Excel or image, up to 10 MB.'],
        ],
        'Budget & timeline' => [
            'engagement_model' => ['label' => 'Preferred engagement model', 'type' => 'select', 'required' => true, 'options' => ['Fixed scope & price', 'Time & material', 'Dedicated team', 'Not sure'], 'col' => 'col-md-4'],
            'budget_range'     => ['label' => 'Budget range', 'type' => 'select', 'required' => true, 'col' => 'col-md-4',
                'options' => ['Under ₹1 lakh', '₹1–5 lakh', '₹5–15 lakh', '₹15–50 lakh', 'Above ₹50 lakh', 'Not decided']],
            'start_date'       => ['label' => 'Preferred start date', 'type' => 'date', 'col' => 'col-md-4'],
            'deadline'         => ['label' => 'Go-live / deadline', 'type' => 'date', 'col' => 'col-md-4'],
            'support_needed'   => ['label' => 'Support after launch?', 'type' => 'yesno', 'col' => 'col-md-4'],
            'notes'            => ['label' => 'Anything else we should know?', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-md-4'],
        ],
    ],
];

/* ───────────────────────── Access & scope ───────────────────────── */

/**
 * Separate permissions: a client login can be limited to one service line (it_login.client_scope = 'hr' | 'it').
 * HR-scoped logins see only vacancies / candidates, IT-scoped logins only projects / deliverables. Staff: always true.
 */
function client_scope_allows(string $service): bool
{
    if (!is_client()) return true;
    $s = user()['client_scope'] ?? null;
    return $s === null || $s === '' || $s === $service;
}

/** Can the current login sign documents for its company? */
function is_client_signatory(): bool
{
    return is_client() && !empty(user()['is_signatory']);
}

/** A client document the current login may see: NDA (no service) or a document of a service in its scope. */
function eng_doc_in_scope(array $d): bool
{
    $s = ($d['eng_service'] ?? null) ?: ($d['service'] ?? null);
    return !$s || client_scope_allows($s);
}

/**
 * May the signed-in client open this stored file (dir clientdocs)? Only files of its own company: documents, its
 * signatures, non-internal message attachments and its request forms, each limited to the login's service scope.
 */
function client_file_allowed(string $path): bool
{
    if (!is_client() || !can('client_services')) return false;
    $cid = my_client_id();
    $ok = fn(?array $r) => $r && (int) $r['client_id'] === $cid && eng_doc_in_scope($r);
    $sql = 'SELECT d.client_id, d.service, e.service eng_service FROM hrm_client_documents d LEFT JOIN hrm_engagements e ON e.id = d.engagement_id';
    if ($r = one("$sql WHERE d.file = ? LIMIT 1", [$path])) return $ok($r);
    if ($r = one('SELECT d.client_id, d.service, e.service eng_service FROM hrm_client_signatures s JOIN hrm_client_documents d ON d.id = s.document_id
                  LEFT JOIN hrm_engagements e ON e.id = d.engagement_id WHERE s.signature_file = ? LIMIT 1', [$path])) return $ok($r);
    if ($r = one('SELECT e.client_id, NULL service, e.service eng_service FROM hrm_engagement_messages m JOIN hrm_engagements e ON e.id = m.engagement_id
                  WHERE m.attachment = ? AND m.internal = 0 LIMIT 1', [$path])) return $ok($r);
    // Files uploaded with a request form (stored inside the form JSON; the random file name is unique).
    $r = one('SELECT client_id, NULL service, service eng_service FROM hrm_engagements WHERE client_id = ? AND form LIKE ? LIMIT 1',
        [$cid, '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], basename($path)) . '%']);
    return $ok($r);
}

/** Staff logins who can own a client request (client-services or authorisation access). */
function eng_owner_options(): array
{
    $out = [];
    foreach (all("SELECT * FROM it_login WHERE status = 'active' ORDER BY username") as $l) {
        $role = normalize_role($l['usertype']);
        if (in_array($role, EXTERNAL_ROLES, true)) continue;
        if ($role === 'admin' || array_intersect(['manage_engagements', 'approve_engagements'], permissions_for($l))) $out[$l['id']] = login_name((int) $l['id']);
    }
    return $out;
}

function eng_staff(): bool
{
    return !is_external() && can_any('manage_engagements', 'approve_engagements');
}

/* ───────────────────────── Loading & saving ───────────────────────── */

function eng_find(int $id): ?array
{
    $e = one('SELECT e.*, c.name client_name, c.email client_email, c.phone client_phone, c.address client_address, c.gst_no client_gstin, c.state client_state,
                     c.contact_person, l.username owner_name, l.email owner_email, s.username submitter, s.email submitter_email
              FROM hrm_engagements e JOIN hrm_clients c ON c.id = e.client_id LEFT JOIN it_login l ON l.id = e.owner_id LEFT JOIN it_login s ON s.id = e.submitted_by
              WHERE e.id = ?', [$id]);
    if (!$e) return null;
    $e['form_data'] = json_decode((string) $e['form'], true) ?: [];
    return $e;
}

/** An engagement the current user may open (staff, or a client login of that company with the right service scope). */
function eng_access(int $id): array
{
    $e = eng_find($id) ?? not_found();
    if (eng_staff()) return $e;
    if (is_client() && (int) $e['client_id'] === my_client_id() && client_scope_allows($e['service']) && can('client_services')) return $e;
    authorize('__never__');
}

function eng_save(int $id, array $data): void
{
    if (isset($data['form']) && is_array($data['form'])) $data['form'] = json_encode($data['form'], JSON_UNESCAPED_UNICODE);
    update('hrm_engagements', $data, $id);
}

function eng_title(array $e): string
{
    $f = $e['form_data'] ?? [];
    return (string) ($e['title'] ?: ($f['position_title'] ?? $f['project_title'] ?? ENG_SERVICES[$e['service']]['form']));
}

function eng_next_ref(string $service): string
{
    $prefix = 'ENG-' . strtoupper($service) . '-' . date('Y') . '-';
    $max = (int) val('SELECT MAX(CAST(SUBSTRING(ref, ?) AS UNSIGNED)) FROM hrm_engagements WHERE ref LIKE ?', [strlen($prefix) + 1, $prefix . '%']);
    return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Audit trail. $actor: client | staff | system. */
function eng_log(int $clientId, ?int $engId, string $event, ?string $note = null, ?string $actor = null): void
{
    insert('hrm_client_events', ['client_id' => $clientId, 'engagement_id' => $engId, 'event' => $event, 'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        'actor' => $actor ?? (is_client() ? 'client' : (user() ? 'staff' : 'system')), 'user_id' => $_SESSION['uid'] ?? null, 'ip' => client_ip()]);
}

const ENG_EVENT_LABELS = [
    'registered' => 'Company registered', 'draft_saved' => 'Request saved as draft', 'submitted' => 'Request submitted', 'info_requested' => 'More information requested',
    'info_provided' => 'Information provided', 'assigned' => 'Owner assigned', 'proposal_sent' => 'Proposal and documents sent for signature',
    'document_signed' => 'Document e-signed', 'document_renewal' => 'New document version issued for signature', 'external_signed' => 'Externally executed copy uploaded',
    'invoice_created' => 'Invoice issued', 'payment_waived' => 'Advance payment waived', 'authorized' => 'Internal authorisation given', 'activated' => 'Activated',
    'report' => 'Status report posted', 'meeting' => 'Meeting scheduled', 'comment' => 'Comment', 'escalated' => 'Escalation raised', 'deliverable' => 'Deliverable shared',
    'deliverable_accepted' => 'Deliverable accepted', 'deliverable_changes' => 'Changes requested on a deliverable', 'delivered' => 'Marked delivered',
    'closed' => 'Closed', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'feedback' => 'Client feedback received', 'milestone' => 'Billing milestone updated',
    'purged' => 'Attachments removed (retention)',
];

/* ───────────────────────── Dynamic forms ───────────────────────── */

/** Is a field shown, given the answers so far? */
function eng_field_visible(array $f, array $answers): bool
{
    if (empty($f['show_if'])) return true;
    [$dep, $vals] = $f['show_if'];
    return in_array((string) ($answers[$dep] ?? ''), $vals, true);
}

/**
 * Reads the posted form for a service. Hidden (conditional) fields are dropped, files stored.
 * Returns [answers, errors]; $strict = false (save draft) skips "required" checks.
 */
function eng_collect_form(string $service, array $old, bool $strict): array
{
    $answers = [];
    $errors = [];
    $all = array_merge(...array_values(ENG_FORMS[$service]));
    // First pass: plain values (needed to evaluate show_if), then visibility + validation.
    foreach ($all as $k => $f) {
        $type = $f['type'] ?? 'text';
        if ($type === 'file') continue;
        $v = $_POST['f'][$k] ?? null;
        if ($type === 'checks') $answers[$k] = array_values(array_intersect($f['options'], array_map('strval', (array) $v)));
        else $answers[$k] = mb_substr(trim((string) $v), 0, $type === 'textarea' ? 5000 : 200);
    }
    foreach ($all as $k => $f) {
        $type = $f['type'] ?? 'text';
        if (!eng_field_visible($f, $answers)) { unset($answers[$k]); continue; }
        if ($type === 'file') {
            try {
                $path = store_upload("f_$k", 'clientdocs', 10);
            } catch (RuntimeException $ex) {
                $errors[] = $f['label'] . ': ' . $ex->getMessage();
                $path = null;
            }
            $answers[$k] = $path ?? ($old[$k] ?? '');
            continue;
        }
        $v = $answers[$k];
        $empty = $v === '' || $v === [];
        if ($empty) {
            if ($strict && !empty($f['required'])) $errors[] = $f['label'] . ' is required.';
            continue;
        }
        if ($type === 'select' && !in_array($v, $f['options'], true)) { $answers[$k] = ''; if ($strict && !empty($f['required'])) $errors[] = $f['label'] . ' is required.'; }
        if ($type === 'yesno' && !in_array($v, ['Yes', 'No'], true)) $answers[$k] = '';
        if ($type === 'number') {
            if (!is_numeric($v)) { $errors[] = $f['label'] . ' must be a number.'; continue; }
            if ((isset($f['min']) && $v < $f['min']) || (isset($f['max']) && $v > $f['max'])) $errors[] = $f['label'] . ' is out of range.';
        }
        if ($type === 'date') {
            if (!valid_date($v)) $errors[] = $f['label'] . ' is not a valid date.';
            elseif ($strict && $v < date('Y-m-d') && in_array($k, ['target_date', 'start_date', 'deadline'], true)) $errors[] = $f['label'] . ' is in the past.';
        }
    }
    if ($strict) {
        if ($service === 'hr' && isset($answers['exp_min'], $answers['exp_max']) && is_numeric($answers['exp_min']) && is_numeric($answers['exp_max']) && $answers['exp_max'] < $answers['exp_min']) $errors[] = 'Experience "to" must be at least "from".';
        if ($service === 'hr' && isset($answers['budget_min'], $answers['budget_max']) && is_numeric($answers['budget_min']) && is_numeric($answers['budget_max']) && $answers['budget_max'] < $answers['budget_min']) $errors[] = 'Budget "to" must be at least "from".';
        if ($service === 'it' && !empty($answers['start_date']) && !empty($answers['deadline']) && $answers['deadline'] < $answers['start_date']) $errors[] = 'The deadline is before the start date.';
    }
    return [$answers, array_values(array_unique($errors))];
}

/** One form field (input name f[key]; files f_key). */
function eng_field(string $k, array $f, $value): string
{
    $type = $f['type'] ?? 'text';
    $id = 'ef_' . $k;
    $req = !empty($f['required']) ? ' <span class="text-danger">*</span>' : '';
    $show = !empty($f['show_if']) ? ' data-show-if="' . e($f['show_if'][0]) . '" data-show-values="' . e(json_encode($f['show_if'][1])) . '"' : '';
    $label = '<label class="form-label" for="' . $id . '">' . e($f['label']) . $req . '</label>';
    $help = !empty($f['help']) ? '<div class="form-text">' . e($f['help']) . '</div>' : '';
    $attrs = '';
    foreach (['min', 'max', 'step', 'placeholder'] as $a) if (isset($f[$a])) $attrs .= ' ' . $a . '="' . e($f[$a]) . '"';
    $name = 'f[' . $k . ']';
    switch ($type) {
        case 'textarea':
            $ctl = '<textarea class="form-control" id="' . $id . '" name="' . $name . '" rows="' . ($f['rows'] ?? 3) . '"' . $attrs . '>' . e($value) . '</textarea>';
            break;
        case 'select':
            $ctl = '<select class="form-select" id="' . $id . '" name="' . $name . '"><option value="">— Select —</option>';
            foreach ($f['options'] as $o) $ctl .= '<option' . ((string) $value === $o ? ' selected' : '') . '>' . e($o) . '</option>';
            $ctl .= '</select>';
            break;
        case 'yesno':
            $ctl = '<div class="d-flex gap-3 pt-1" id="' . $id . '">';
            foreach (['Yes', 'No'] as $o) $ctl .= '<label class="form-check"><input class="form-check-input" type="radio" name="' . $name . '" value="' . $o . '"' . ((string) $value === $o ? ' checked' : '') . '> <span class="form-check-label">' . $o . '</span></label>';
            $ctl .= '</div>';
            break;
        case 'checks':
            $vals = (array) $value;
            $ctl = '<div class="d-flex flex-wrap gap-3 pt-1" id="' . $id . '">';
            foreach ($f['options'] as $o) $ctl .= '<label class="form-check"><input class="form-check-input" type="checkbox" name="' . $name . '[]" value="' . e($o) . '"' . (in_array($o, $vals, true) ? ' checked' : '') . '> <span class="form-check-label">' . e($o) . '</span></label>';
            $ctl .= '</div>';
            break;
        case 'file':
            $ctl = '<input class="form-control" type="file" id="' . $id . '" name="f_' . $k . '" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg">'
                . ($value ? '<div class="form-text"><i class="ri-attachment-2"></i> <a href="' . e(file_url($value)) . '" target="_blank" rel="noopener">Current file</a> — upload to replace.</div>' : '');
            break;
        default:
            $html = in_array($type, ['number', 'date'], true) ? $type : 'text';
            $ctl = '<input class="form-control" type="' . $html . '" id="' . $id . '" name="' . $name . '" value="' . e($value) . '"' . $attrs . '>';
    }
    return '<div class="' . ($f['col'] ?? 'col-md-6') . '"' . $show . '>' . $label . $ctl . $help . '</div>';
}

/** The answers as a definition list (staff view, client view, documents). */
function eng_answers_html(array $e, bool $forDocument = false): string
{
    $out = '';
    foreach (ENG_FORMS[$e['service']] as $section => $fields) {
        $rows = '';
        foreach ($fields as $k => $f) {
            if (!array_key_exists($k, $e['form_data'])) continue;
            $v = $e['form_data'][$k];
            if ($v === '' || $v === []) continue;
            if (($f['type'] ?? '') === 'file') { if ($forDocument) continue; $v = '<a href="' . e(file_url($v)) . '" target="_blank" rel="noopener"><i class="ri-attachment-2"></i> Open file</a>'; }
            elseif (is_array($v)) $v = e(implode(', ', $v));
            elseif (($f['type'] ?? '') === 'date') $v = fdate($v);
            else $v = nl2br(e($v));
            $rows .= $forDocument ? '<tr><td>' . e($f['label']) . '</td><td>' . $v . '</td></tr>' : '<div><dt>' . e($f['label']) . '</dt><dd>' . $v . '</dd></div>';
        }
        if ($rows === '') continue;
        $out .= $forDocument ? '<tr><th colspan="2">' . e($section) . '</th></tr>' . $rows : '<h6 class="eng-sec">' . e($section) . '</h6><dl class="detail-grid">' . $rows . '</dl>';
    }
    return $forDocument ? '<table class="table table-sm eng-doc-table">' . $out . '</table>' : $out;
}

/* ───────────────────────── Commercials ───────────────────────── */

function eng_gst_rate(): float
{
    return (float) setting('gst_percent', 18);
}

/** The advance including GST (what the advance invoice totals). */
function eng_advance_with_tax(array $e): float
{
    return round((float) $e['advance_amount'] * (1 + eng_gst_rate() / 100), 2);
}

function eng_commercials_html(array $e): string
{
    $models = ENG_FEE_MODELS[$e['service']];
    $fee = match ($e['fee_model']) {
        'percent' => rtrim(rtrim(number_format((float) $e['fee_value'], 2), '0'), '.') . '% of the annual CTC of each candidate who joins',
        'tm' => money($e['fee_value']) . ' per hour',
        'dedicated' => money($e['fee_value']) . ' per resource per month',
        default => money($e['fee_value']) . ($e['service'] === 'hr' ? ' per hire' : ''),
    };
    $rows = [['Service', ENG_SERVICES[$e['service']]['label'] . ' — ' . eng_title($e)], ['Fee model', $models[$e['fee_model']] ?? '—'], ['Fee', $fee]];
    if ((float) $e['estimate_amount'] > 0) $rows[] = ['Estimated value', money($e['estimate_amount']) . ' + GST'];
    $rows[] = ['Advance payable before activation', (float) $e['advance_amount'] > 0 ? money($e['advance_amount']) . ' + GST ' . rtrim(rtrim(number_format(eng_gst_rate(), 2), '0'), '.') . '% = ' . money(eng_advance_with_tax($e), true) : 'Nil'];
    if ($e['service'] === 'hr' && $e['replacement_days']) $rows[] = ['Replacement guarantee', (int) $e['replacement_days'] . ' days from the joining date'];
    if ($e['payment_terms']) $rows[] = ['Payment terms', $e['payment_terms']];
    if ($e['valid_until']) $rows[] = ['Proposal valid until', fdate($e['valid_until'], 'd F Y')];
    $html = '<table class="table table-sm eng-doc-table"><tbody>';
    foreach ($rows as [$l, $v]) $html .= '<tr><td>' . e($l) . '</td><td><strong>' . nl2br(e($v)) . '</strong></td></tr>';
    $ms = all('SELECT * FROM hrm_engagement_milestones WHERE engagement_id = ? ORDER BY sort, due_date, id', [$e['id']]);
    $html .= '</tbody></table>';
    if ($ms) {
        $html .= '<table class="table table-sm eng-doc-table"><thead><tr><th>Payment milestone</th><th>Due</th><th class="text-end">Amount (+GST)</th></tr></thead><tbody>';
        foreach ($ms as $m) $html .= '<tr><td>' . e($m['title']) . '</td><td>' . fdate($m['due_date']) . '</td><td class="text-end">' . money($m['amount']) . '</td></tr>';
        $html .= '</tbody></table>';
    }
    return $html;
}

/* ───────────────────────── Documents, versions & signatures ───────────────────────── */

function eng_placeholders(array $e, ?array $client = null): array
{
    $client ??= one('SELECT * FROM hrm_clients WHERE id = ?', [$e['client_id']]);
    $f = $e['form_data'] ?? [];
    return [
        'client_company' => $client['name'], 'client_address' => trim((string) $client['address']) ?: '—', 'client_gstin' => $client['gst_no'] ?: 'Not registered',
        'client_contact' => (string) $client['contact_person'], 'client_email' => (string) $client['email'],
        'engagement_ref' => (string) ($e['ref'] ?? ''), 'engagement_title' => eng_title($e), 'service' => ENG_SERVICES[$e['service']]['label'],
        'start_date' => fdate($f['start_date'] ?? null, 'd F Y'), 'deadline' => fdate($f['deadline'] ?? ($f['target_date'] ?? null), 'd F Y'),
        'replacement_days' => (string) ((int) ($e['replacement_days'] ?? 0) ?: (int) setting('eng_replacement_days', 90)),
        'payment_terms' => (string) ($e['payment_terms'] ?: setting('invoice_due_days', 15) . ' days from the invoice date'),
        'validity_date' => fdate($e['valid_until'] ?? null, 'd F Y'), 'advance_amount' => money($e['advance_amount'] ?? 0),
        'company_name' => setting('company_name'), 'company_address' => (string) setting('company_address', ''), 'company_gstin' => (string) setting('company_gstin', ''),
        'today' => fdate(date('Y-m-d'), 'd F Y'), 'jurisdiction' => (string) setting('eng_jurisdiction', 'Noida, Uttar Pradesh'),
    ];
}

/** Template text → HTML (escaped Markdown subset) with {{placeholders}}, plus the block placeholders. */
function eng_render(string $body, array $e): string
{
    $html = policy_html($body);
    $blocks = ['requirement_summary' => eng_answers_html($e, true), 'commercials_table' => eng_commercials_html($e), 'sop_scope' => policy_html((string) ($e['sop_text'] ?? ''))];
    foreach ($blocks as $k => $v) $html = str_replace(['<p>{{' . $k . '}}</p>', '{{' . $k . '}}'], [$v, $v], $html);
    $map = [];
    foreach (eng_placeholders($e) as $k => $v) $map['{{' . $k . '}}'] = nl2br(e($v));
    return strtr($html, $map);
}

function eng_template(string $kind): ?array
{
    return one('SELECT * FROM hrm_onboarding_templates WHERE kind = ? AND active = 1 ORDER BY is_default DESC, id LIMIT 1', [$kind]);
}

/** The current (latest, not void) document of a kind for this engagement / company. */
function eng_current_doc(array $e, string $kind): ?array
{
    $scope = ENG_DOC_KINDS[$kind]['scope'];
    return $scope === 'company'
        ? one("SELECT * FROM hrm_client_documents WHERE client_id = ? AND kind = ? AND status <> 'Void' ORDER BY version DESC, id DESC LIMIT 1", [$e['client_id'], $kind])
        : one("SELECT * FROM hrm_client_documents WHERE engagement_id = ? AND kind = ? AND status <> 'Void' ORDER BY version DESC, id DESC LIMIT 1", [$e['id'], $kind]);
}

/**
 * Issues the documents an engagement needs for signature. A signed document is never changed: when the text that would be
 * issued now differs from the signed text (template updated, SOP / commercials edited), a new version is created and the
 * client signs again. Unsigned drafts are replaced in place. Returns the documents that now await a signature.
 */
function eng_issue_documents(array $e): array
{
    $pending = [];
    foreach (ENG_REQUIRED_DOCS[$e['service']] as $kind) {
        $meta = ENG_DOC_KINDS[$kind];
        $tpl = eng_template($meta['template']);
        if (!$tpl) throw new RuntimeException('There is no active template for the ' . strtolower($meta['label']) . '. Add one under Client onboarding → Templates.');
        $html = eng_render($tpl['body'], $e);
        $hash = hash('sha256', $html);
        $cur = eng_current_doc($e, $kind);
        // Company-level documents are compared by template version: client details changing alone doesn't force a re-sign.
        $same = $cur && ($meta['scope'] === 'company' ? (int) $cur['template_version'] === (int) $tpl['version'] : $cur['content_hash'] === $hash);
        if ($cur && $same && in_array($cur['status'], ['Signed', 'Awaiting Signature'], true)) {
            if ($cur['status'] === 'Awaiting Signature') $pending[] = $cur;
            continue;
        }
        $title = $meta['label'] . ($meta['scope'] === 'engagement' ? ' — ' . eng_title($e) : '');
        if ($cur && $cur['status'] === 'Awaiting Signature') {
            update('hrm_client_documents', ['content' => $html, 'content_hash' => $hash, 'template_version' => $tpl['version'], 'title' => $title, 'updated_at' => date('Y-m-d H:i:s')], (int) $cur['id']);
            $pending[] = one('SELECT * FROM hrm_client_documents WHERE id = ?', [$cur['id']]);
            continue;
        }
        $id = insert('hrm_client_documents', ['client_id' => $e['client_id'], 'engagement_id' => $meta['scope'] === 'engagement' ? $e['id'] : null, 'kind' => $kind,
            'service' => $meta['scope'] === 'company' && $kind !== 'nda' ? $e['service'] : null, 'title' => mb_substr($title, 0, 190), 'version' => $cur ? (int) $cur['version'] + 1 : 1,
            'content' => $html, 'content_hash' => $hash, 'template_version' => $tpl['version'], 'status' => 'Awaiting Signature', 'requires_signature' => 1,
            'supersedes_id' => $cur['id'] ?? null, 'created_by' => user()['id'] ?? null]);
        if ($cur) {
            q('UPDATE hrm_client_documents SET superseded_by = ?, is_current = 0 WHERE id = ?', [$id, $cur['id']]);
            eng_log((int) $e['client_id'], (int) $e['id'], 'document_renewal', $meta['label'] . ' v' . ((int) $cur['version'] + 1) . ' (v' . $cur['version'] . ' ' . strtolower($cur['status']) . ')');
        }
        $pending[] = one('SELECT * FROM hrm_client_documents WHERE id = ?', [$id]);
    }
    return $pending;
}

/** Required documents for an engagement with their current version (or null). */
function eng_required_docs(array $e): array
{
    $out = [];
    foreach (ENG_REQUIRED_DOCS[$e['service']] as $kind) $out[$kind] = eng_current_doc($e, $kind);
    return $out;
}

function eng_signatures(int $docId): array
{
    return all('SELECT * FROM hrm_client_signatures WHERE document_id = ? ORDER BY id', [$docId]);
}

/** Documents of a client awaiting signature (optionally only those an engagement needs). */
function eng_docs_awaiting(int $clientId): array
{
    return all("SELECT d.*, e.ref, e.service eng_service FROM hrm_client_documents d LEFT JOIN hrm_engagements e ON e.id = d.engagement_id
                WHERE d.client_id = ? AND d.status = 'Awaiting Signature' ORDER BY d.created_at", [$clientId]);
}

/* ───────────────────────── Gates & status ───────────────────────── */

function eng_advance_invoice(array $e): ?array
{
    return $e['advance_invoice_id'] ? one('SELECT * FROM hrm_invoices WHERE id = ?', [$e['advance_invoice_id']]) : null;
}

function eng_payment_ok(array $e): bool
{
    if ((float) $e['advance_amount'] <= 0 || $e['payment_waived']) return true;
    $inv = eng_advance_invoice($e);
    return $inv && $inv['status'] !== 'Cancelled' && (float) $inv['paid'] + 0.009 >= (float) $inv['total'];
}

/** The activation conditions: key => [label, ok]. */
function eng_gates(array $e): array
{
    $docs = eng_required_docs($e);
    $proposal = $docs['proposal'] ?? null;
    $others = array_diff_key($docs, ['proposal' => 1]);
    $unsigned = array_filter($others, fn($d) => !$d || $d['status'] !== 'Signed');
    return [
        'proposal'  => ['Commercial proposal accepted by an authorised signatory', $proposal && $proposal['status'] === 'Signed'],
        'documents' => ['Agreements signed (' . implode(', ', array_map(fn($k) => ENG_DOC_KINDS[$k]['label'], array_keys($others))) . ')', !$unsigned],
        'payment'   => [(float) $e['advance_amount'] > 0 ? 'Advance payment received' . ($e['payment_waived'] ? ' (waived)' : '') : 'No advance payment required', eng_payment_ok($e)],
        'authorize' => ['Internal authorisation', (bool) $e['authorized_at']],
    ];
}

/** Recomputes the status while the engagement is in the proposal / payment / authorisation stage. */
function eng_refresh_status(int $id): void
{
    $e = eng_find($id);
    if (!$e || !in_array($e['status'], ENG_PROPOSAL_STAGE, true)) return;
    $g = eng_gates($e);
    $status = !$g['proposal'][1] || !$g['documents'][1] ? 'Proposal Sent' : (!$g['payment'][1] ? 'Awaiting Payment' : 'Awaiting Authorization');
    if ($status !== $e['status']) {
        eng_save($id, ['status' => $status]);
        if ($status === 'Awaiting Authorization') {
            eng_notify_staff($e, 'ready for authorisation', 'All documents are signed' . ((float) $e['advance_amount'] > 0 ? ' and the advance is paid' : '') . '. Authorise to activate the ' . strtolower(ENG_SERVICES[$e['service']]['output']) . '.', true);
        }
    }
}

/** After a company-level document changes, every open engagement of the client is re-checked. */
function eng_refresh_client(int $clientId): void
{
    foreach (all('SELECT id FROM hrm_engagements WHERE client_id = ? AND status IN ' . sql_list(ENG_PROPOSAL_STAGE), [$clientId]) as $r) eng_refresh_status((int) $r['id']);
}

/** Phase key the engagement is in. */
function eng_phase(array $e): string
{
    foreach (ENG_PHASES as $k => [, $statuses]) if (in_array($e['status'], $statuses, true)) return $k;
    return in_array($e['status'], ['Declined', 'Cancelled'], true) ? 'closure' : 'request';
}

function eng_tracker(array $e): string
{
    $keys = array_keys(ENG_PHASES);
    $cur = array_search(eng_phase($e), $keys, true);
    $stopped = in_array($e['status'], ['Declined', 'Cancelled'], true);
    $html = '<ol class="eng-track' . ($stopped ? ' stopped' : '') . '">';
    foreach (ENG_PHASES as $k => [$label]) {
        $i = array_search($k, $keys, true);
        $cls = $e['status'] === 'Closed' || $i < $cur ? 'done' : ($i === $cur ? 'current' : '');
        $html .= '<li class="' . $cls . '"><span>' . ($cls === 'done' ? '<i class="ri-check-line"></i>' : $i + 1) . '</span>' . e($label) . '</li>';
    }
    return $html . '</ol>';
}

/* ───────────────────────── Billing ───────────────────────── */

/** Creates a GST invoice for the client, linked to the engagement. Returns the invoice id. */
function eng_create_invoice(array $e, string $description, float $amount, ?int $milestoneId = null): int
{
    $client = one('SELECT * FROM hrm_clients WHERE id = ?', [$e['client_id']]);
    $place = $client['state'] ?: company_state();
    $rate = eng_gst_rate();
    $tax = invoice_tax($amount, $rate, $place);
    $id = insert('hrm_invoices', ['invoice_no' => next_client_invoice_no(), 'client_id' => $e['client_id'], 'project_id' => $e['project_id'] ?: null, 'engagement_id' => $e['id'],
        'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+' . max(0, (int) setting('invoice_due_days', 15)) . ' days')), 'amount' => round($amount, 2),
        'tax_percent' => $rate, 'place_of_supply' => $place, 'cgst' => $tax['cgst'], 'sgst' => $tax['sgst'], 'igst' => $tax['igst'], 'total' => round($amount + $tax['total_tax'], 2),
        'paid' => 0, 'status' => 'Unpaid', 'notes' => 'Ref: ' . $e['ref']]);
    save_invoice_items($id, [['description' => mb_substr($description, 0, 500), 'sac' => setting($e['service'] === 'hr' ? 'eng_hr_sac' : 'client_invoice_sac', $e['service'] === 'hr' ? '998512' : '998313'),
        'qty' => 1, 'rate' => round($amount, 2), 'amount' => round($amount, 2)]]);
    if ($milestoneId) q("UPDATE hrm_engagement_milestones SET invoice_id = ?, status = 'Invoiced' WHERE id = ?", [$id, $milestoneId]);
    $inv = one('SELECT * FROM hrm_invoices WHERE id = ?', [$id]);
    eng_log((int) $e['client_id'], (int) $e['id'], 'invoice_created', $inv['invoice_no'] . ' · ' . money($inv['total'], true));
    eng_notify_client($e, "Invoice {$inv['invoice_no']} for {$e['ref']}", "Invoice {$inv['invoice_no']} for " . money($inv['total'], true) . " (incl. GST) has been issued for: $description.\n\nDue date: " . fdate($inv['due_date'], 'd F Y')
        . "\n\nView and download it in the client portal: " . site_origin() . url('client', ['a' => 'invoice', 'id' => $id]) . (setting('invoice_bank_details') ? "\n\nBank details:\n" . setting('invoice_bank_details') : ''), 'billing');
    return $id;
}

/* ───────────────────────── Activation & closure ───────────────────────── */

/** Creates the vacancy (HR) or project (IT) from the approved request. Returns [entity, id]. */
function eng_activate(array $e, ?int $managerEmployeeId = null): array
{
    $f = $e['form_data'];
    if ($e['service'] === 'hr') {
        $exp = isset($f['exp_min'], $f['exp_max']) ? rtrim(rtrim((string) $f['exp_min'], '0'), '.') . '–' . rtrim(rtrim((string) $f['exp_max'], '0'), '.') . ' years' : null;
        $salary = isset($f['budget_min'], $f['budget_max']) ? '₹' . $f['budget_min'] . '–' . $f['budget_max'] . ' LPA' : null;
        $desc = trim(($f['job_description'] ?? '') . (!empty($f['skills_nice']) ? "\n\nGood to have: " . $f['skills_nice'] : '') . (!empty($f['qualification']) ? "\n\nQualification: " . $f['qualification'] : '')
            . (!empty($f['notice_period']) ? "\nNotice period: " . $f['notice_period'] : ''));
        $id = insert('hrm_jobs', ['title' => mb_substr($f['position_title'] ?? eng_title($e), 0, 150), 'location' => mb_substr(($f['work_mode'] ?? '') === 'Remote' ? 'Remote' : ($f['locations'] ?? ''), 0, 120) ?: null,
            'employment_type' => in_array($f['employment_type'] ?? '', ['Full-time', 'Part-time', 'Contract', 'Intern'], true) ? $f['employment_type'] : (($f['employment_type'] ?? '') === 'Internship' ? 'Intern' : 'Contract'),
            'openings' => max(1, (int) ($f['openings'] ?? 1)), 'experience' => $exp, 'salary_range' => $salary, 'description' => $desc ?: null, 'skills' => mb_substr((string) ($f['skills_must'] ?? ''), 0, 255) ?: null,
            'status' => 'Open', 'closing_date' => valid_date($f['target_date'] ?? null), 'client_id' => $e['client_id'],
            'visibility' => ($f['confidential'] ?? 'No') === 'Yes' ? 'Internal' : (string) setting('eng_job_visibility', 'Vendors'),
            'posted_by' => $e['submitted_by'], 'origin' => 'Client', 'published_at' => date('Y-m-d H:i:s'), 'approved_by' => user()['id'] ?? null]);
        eng_save((int) $e['id'], ['job_id' => $id]);
        return ['job', $id];
    }
    $budget = (float) $e['estimate_amount'] > 0 ? (float) $e['estimate_amount'] : null;
    $id = insert('hrm_projects', ['name' => mb_substr($f['project_title'] ?? eng_title($e), 0, 150), 'client_id' => $e['client_id'], 'manager_id' => $managerEmployeeId,
        'start_date' => valid_date($f['start_date'] ?? null) ?? date('Y-m-d'), 'deadline' => valid_date($f['deadline'] ?? null), 'budget' => $budget, 'priority' => 'High', 'status' => 'In Progress',
        'progress' => 0, 'description' => mb_substr(($f['service_type'] ?? '') . ' · ' . $e['ref'] . "\n\n" . ($f['description'] ?? ''), 0, 5000)]);
    // Scope lines become the first tasks, so the client sees progress from day one.
    foreach (array_slice(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($f['features'] ?? '')))), 0, 30) as $line) {
        insert('hrm_tasks', ['project_id' => $id, 'title' => mb_substr(ltrim($line, '-•* '), 0, 200), 'status' => 'To Do', 'priority' => 'Medium']);
    }
    eng_save((int) $e['id'], ['project_id' => $id]);
    q('UPDATE hrm_invoices SET project_id = ? WHERE engagement_id = ? AND project_id IS NULL', [$id, $e['id']]);
    return ['project', $id];
}

/** Delivery figures for the execution / delivery view. */
function eng_delivery(array $e): array
{
    if ($e['service'] === 'hr' && $e['job_id']) {
        $c = array_column(all('SELECT stage, COUNT(*) n FROM hrm_candidates WHERE job_id = ? GROUP BY stage', [$e['job_id']]), 'n', 'stage');
        $openings = (int) val('SELECT openings FROM hrm_jobs WHERE id = ?', [$e['job_id']]);
        $short = array_sum(array_intersect_key($c, array_flip(STAGES_SHORTLISTED)));
        return ['kind' => 'hr', 'openings' => $openings, 'profiles' => array_sum($c), 'shortlisted' => $short, 'interviews' => array_sum(array_intersect_key($c, array_flip(STAGES_INTERVIEWING))),
            'offered' => (int) ($c['Offered'] ?? 0), 'joined' => (int) ($c['Hired'] ?? 0), 'pct' => $openings ? min(100, (int) round(($c['Hired'] ?? 0) / $openings * 100)) : 0];
    }
    if ($e['service'] === 'it' && $e['project_id']) {
        $p = one('SELECT * FROM hrm_projects WHERE id = ?', [$e['project_id']]);
        $t = one("SELECT COUNT(*) n, SUM(status = 'Done') d FROM hrm_tasks WHERE project_id = ?", [$e['project_id']]);
        $dl = one("SELECT COUNT(*) n, SUM(status = 'Accepted') a FROM hrm_client_documents WHERE engagement_id = ? AND kind = 'deliverable' AND status <> 'Void'", [$e['id']]);
        return ['kind' => 'it', 'progress' => (int) ($p['progress'] ?? 0), 'project_status' => $p['status'] ?? '', 'tasks' => (int) $t['n'], 'done' => (int) $t['d'],
            'deliverables' => (int) $dl['n'], 'accepted' => (int) $dl['a'], 'pct' => (int) ($p['progress'] ?? 0)];
    }
    return ['kind' => $e['service'], 'pct' => 0];
}

/* ───────────────────────── Notifications ───────────────────────── */

/** Active client logins who should hear about an engagement (service scope respected). $signatoriesOnly for signature requests. */
function eng_client_recipients(int $clientId, ?string $service, bool $signatoriesOnly = false): array
{
    $out = [];
    foreach (all("SELECT * FROM it_login WHERE client_id = ? AND status = 'active' AND email IS NOT NULL AND email <> ''", [$clientId]) as $l) {
        if ($service && !empty($l['client_scope']) && $l['client_scope'] !== $service) continue;
        if ($signatoriesOnly && empty($l['is_signatory'])) continue;
        $out[strtolower($l['email'])] = $l;
    }
    return array_values($out);
}

/** E-mails the client's contacts. $kind 'signature' → signatories only. */
function eng_notify_client(array $e, string $subject, string $body, string $kind = 'update'): void
{
    $company = setting('company_name');
    foreach (eng_client_recipients((int) $e['client_id'], $e['service'] ?? null, $kind === 'signature') as $l) {
        send_mail($l['email'], "$subject · $company", "Hello {$l['username']},\n\n$body\n\n— $company Client Services\n" . (setting('eng_email') ?: setting('company_email')), setting('eng_email') ?: setting('company_email'));
    }
}

/** E-mails the owner and the client-services inbox ($approvers adds everyone who can authorise). */
function eng_notify_staff(array $e, string $subject, string $body, bool $approvers = false): void
{
    notify(array_merge([(int) ($e['owner_id'] ?? 0)], logins_with($approvers ? 'approve_engagements' : 'manage_engagements', $approvers)), 'client', ($e['ref'] ?? 'Request') . ': ' . $subject, mb_strimwidth($body, 0, 300, '…'), url('engagements', ['a' => 'view', 'id' => $e['id']]), 'ri-service-line', 'info', 'eng:' . $e['id'] . ':' . md5($subject . date('YmdH')));
    $to = array_filter([$e['owner_email'] ?? null, setting('eng_email') ?: setting('company_email')]);
    if ($approvers) {
        foreach (all("SELECT * FROM it_login WHERE status = 'active' AND email IS NOT NULL AND email <> ''") as $l) {
            $role = normalize_role($l['usertype']);
            if (!in_array($role, EXTERNAL_ROLES, true) && ($role === 'admin' || in_array('approve_engagements', permissions_for($l), true))) $to[] = $l['email'];
        }
    }
    $link = site_origin() . url('engagements', ['a' => 'view', 'id' => $e['id']]);
    foreach (array_unique(array_map('strtolower', $to)) as $m) {
        if (filter_var($m, FILTER_VALIDATE_EMAIL)) send_mail($m, "[{$e['ref']}] {$e['client_name']}: $subject", "$body\n\nOpen: $link\n\n— " . setting('company_name') . ' HRMS');
    }
}

/** Interview / selection / joining updates on a client's vacancy go to the client's HR contacts (called from notify_stage_change()). */
function eng_notify_candidate_stage(int $candidateId, string $stage): void
{
    if (!in_array($stage, ['Client Interview', 'Selected', 'Offered', 'Hired'], true)) return;
    $c = one('SELECT c.name, c.interview_at, c.joining_date, j.title, j.client_id FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id = ? AND j.client_id IS NOT NULL', [$candidateId]);
    if (!$c) return;
    $what = ['Client Interview' => 'is scheduled for your interview' . ($c['interview_at'] ? ' on ' . fdate($c['interview_at'], 'l, d M Y, h:i A') : ''),
        'Selected' => 'has been marked selected', 'Offered' => 'has been offered the position' . ($c['joining_date'] ? ', joining ' . fdate($c['joining_date']) : ''), 'Hired' => 'has joined'][$stage];
    $company = setting('company_name');
    foreach (eng_client_recipients((int) $c['client_id'], 'hr') as $l) {
        send_mail($l['email'], "[{$c['title']}] {$c['name']} $what", "Hello {$l['username']},\n\n{$c['name']} $what for the position {$c['title']}.\n\nSee the candidate in your portal: " . site_origin() . url('client', ['a' => 'hiring']) . "\n\n— $company Recruitment");
    }
}

/**
 * Reminders, run at most every 30 minutes from normal page loads (and from cron.php when a cron job is set up).
 * Each reminder is logged in hrm_notification_log under a unique key, so nothing is sent twice.
 */
function eng_reminders_tick(bool $force = false): int
{
    if (!$force) {
        if (time() - (int) setting('eng_tick_at', 0) < 1800) return 0;
        set_setting('eng_tick_at', time()); // two parallel runs are harmless: every reminder key is unique
    }
    $sent = 0;
    $once = function (string $key) use (&$sent): bool {
        try { insert('hrm_notification_log', ['k' => mb_substr($key, 0, 190)]); $sent++; return true; } catch (PDOException $ex) { return false; }
    };
    $every = max(1, (int) setting('eng_reminder_days', 3));
    // 1. Documents waiting for a signature: every N days, at most 3 reminders.
    foreach (all("SELECT d.*, e.ref, e.service, e.id eid FROM hrm_client_documents d LEFT JOIN hrm_engagements e ON e.id = d.engagement_id
                  WHERE d.status = 'Awaiting Signature' AND d.created_at < ?", [date('Y-m-d H:i:s', time() - $every * 86400)]) as $d) {
        $n = min(3, (int) floor((time() - strtotime($d['created_at'])) / ($every * 86400)));
        if ($n < 1 || !$once("docsig:{$d['id']}:$n")) continue;
        eng_notify_client(['client_id' => $d['client_id'], 'service' => $d['service'] ?? null], 'Reminder: document awaiting your signature',
            "\"{$d['title']}\" (version {$d['version']}) is still waiting for your signature.\n\nReview and sign: " . site_origin() . url('services', ['a' => 'sign', 'id' => $d['id']]), 'signature');
    }
    // 2. Missing information not yet provided.
    foreach (all("SELECT * FROM hrm_engagements WHERE status = 'Info Requested' AND info_requested_at < ?", [date('Y-m-d H:i:s', time() - $every * 86400)]) as $r) {
        $n = min(3, (int) floor((time() - strtotime($r['info_requested_at'])) / ($every * 86400)));
        if ($n < 1 || !$once("info:{$r['id']}:{$r['info_requested_at']}:$n")) continue;
        $r['form_data'] = [];
        eng_notify_client($r, "Reminder: information needed for {$r['ref']}", "We are waiting for some information before we can prepare your proposal:\n\n{$r['info_note']}\n\nUpdate your request: " . site_origin() . url('services', ['a' => 'view', 'id' => $r['id']]));
    }
    // 3. Invoices of clients with engagements: 3 days before the due date, on the day after it, then weekly (max 3).
    foreach (all("SELECT i.*, e.service, e.ref FROM hrm_invoices i JOIN hrm_engagements e ON e.id = i.engagement_id WHERE i.status IN ('Unpaid','Partially Paid') AND i.due_date IS NOT NULL") as $i) {
        $days = (int) floor((strtotime($i['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $key = $days <= 3 && $days >= 0 ? "inv:{$i['id']}:due" : ($days < 0 ? "inv:{$i['id']}:over:" . min(3, intdiv(-$days - 1, 7) + 1) : null);
        if (!$key || !$once($key)) continue;
        eng_notify_client(['client_id' => $i['client_id'], 'service' => $i['service']], ($days >= 0 ? 'Payment due' : 'Payment overdue') . ": invoice {$i['invoice_no']}",
            "Invoice {$i['invoice_no']} ({$i['ref']}) for " . money($i['total'] - $i['paid'], true) . ' is ' . ($days >= 0 ? 'due on ' . fdate($i['due_date'], 'd F Y') : 'overdue since ' . fdate($i['due_date'], 'd F Y'))
            . ".\n\nView it: " . site_origin() . url('client', ['a' => 'invoice', 'id' => $i['id']]) . (setting('invoice_bank_details') ? "\n\nBank details:\n" . setting('invoice_bank_details') : ''), 'billing');
    }
    if (random_int(1, 20) === 1) q('DELETE FROM hrm_notification_log WHERE sent_at < ?', [date('Y-m-d H:i:s', time() - 400 * 86400)]);
    return $sent;
}

/** Counts for the menu / bell. Staff: requests needing action; clients: signatures, information requests, invoices due. */
function eng_counts(): array
{
    $c = ['eng_staff' => 0, 'eng_client' => 0];
    if (!user() || !plan_allows('manage_engagements')) return $c;
    if (eng_staff()) {
        $c['eng_staff'] = (int) val("SELECT COUNT(*) FROM hrm_engagements WHERE status IN ('Submitted'" . (can('approve_engagements') ? ",'Awaiting Authorization'" : '') . ')');
    } elseif (is_client() && can('client_services')) {
        $cid = my_client_id();
        $sig = is_client_signatory() ? count(array_filter(eng_docs_awaiting($cid), fn($d) => doc_can($d, 'sign'))) : 0;
        $info = (int) val("SELECT COUNT(*) FROM hrm_engagements WHERE client_id = ? AND status = 'Info Requested'", [$cid]);
        $c['eng_client'] = $sig + $info;
    }
    return $c;
}

/** Records a consent (registration, privacy notice, e-sign) with the exact text's fingerprint. */
function record_consent(?int $loginId, ?int $clientId, string $purpose, string $text): void
{
    insert('hrm_consents', ['login_id' => $loginId, 'client_id' => $clientId, 'purpose' => $purpose, 'text_hash' => hash('sha256', $text), 'text' => mb_substr($text, 0, 2000),
        'ip' => client_ip(), 'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
}

/* ───────────────────────── Document rendering ───────────────────────── */

/** Letter-style rendering of a client document. */
function eng_doc_letter(array $d, string $footer = ''): string
{
    $logo = setting('company_logo');
    $body = $d['content'] ? '<div class="policy-body">' . $d['content'] . '</div>' : ($d['file'] ? '<p><a class="btn btn-light border" href="' . e(file_url($d['file'])) . '" target="_blank" rel="noopener"><i class="ri-file-download-line me-1"></i>Open ' . e($d['original_name'] ?: basename($d['file'])) . '</a></p>' : '');
    return '<article class="onb-letter letter"><header class="onb-letterhead"><img src="' . e($logo ? file_url($logo) : asset('images/zveca-logo.png')) . '" alt="">'
        . '<div><strong>' . e(setting('company_name')) . '</strong><small>' . nl2br(e((string) setting('company_address', ''))) . '</small></div>'
        . '<div class="text-end small"><span class="text-muted">Version</span> ' . (int) $d['version'] . ($d['ref'] ?? null ? '<br><span class="text-muted">Ref</span> ' . e($d['ref']) : '') . '</div></header>'
        . '<h2 class="onb-letter-title">' . e(ENG_DOC_KINDS[$d['kind']]['label'] ?? $d['title']) . '</h2>' . $body . $footer . '</article>';
}

/** Signature evidence block (certificate). */
function eng_certificate(array $d): string
{
    $sigs = eng_signatures((int) $d['id']);
    if (!$sigs) return '';
    $intact = $d['content'] === null || hash_equals((string) $d['content_hash'], hash('sha256', (string) $d['content']));
    $html = '<footer class="onb-audit"><h3 class="h6 fw-bold mt-4">Signature certificate</h3>';
    foreach ($sigs as $s) {
        $html .= '<div class="eng-sig"><div class="onb-sigline">' . ($s['signature_file'] ? '<img src="' . e(file_url($s['signature_file'])) . '" alt="Signature">' : '<span class="eng-sig-stamp"><i class="ri-shield-check-line"></i></span>')
            . '<div><strong>' . e($s['signer_name']) . '</strong><small>' . e($s['signer_designation'] ?: '') . ' · ' . ($s['party'] === 'company' ? e(setting('company_name')) : ($s['party'] === 'vendor' ? 'Vendor' : 'Client')) . '</small></div></div>'
            . '<dl><div><dt>Signed at</dt><dd>' . fdate($s['signed_at'], 'd M Y, h:i:s A') . ' IST</dd></div>'
            . '<div><dt>Method</dt><dd>' . e(['otp_draw' => 'E-mail OTP verified + drawn signature', 'external' => 'Executed via external e-sign provider (copy uploaded)', 'internal' => 'Accepted on internal authorisation'][$s['method']] ?? (defined('DOC_METHOD_LABELS') ? (DOC_METHOD_LABELS[$s['method']] ?? $s['method']) : $s['method'])) . '</dd></div>'
            . ($s['signer_email'] ? '<div><dt>Verified e-mail</dt><dd>' . e($s['signer_email']) . '</dd></div>' : '') . ($s['otp_verified_at'] ? '<div><dt>OTP verified</dt><dd>' . fdate($s['otp_verified_at'], 'd M Y, h:i:s A') . '</dd></div>' : '')
            . ($s['ip'] ? '<div><dt>IP · device</dt><dd>' . e($s['ip']) . ' · ' . e(device_label((string) $s['user_agent'])) . '</dd></div>' : '') . '</dl></div>';
    }
    return $html . '<p class="small mt-2 mb-0">SHA-256 of the signed text: <code class="text-break">' . e($d['content_hash']) . '</code> '
        . ($d['content'] !== null ? ($intact ? '<span class="pill pill-success">Integrity verified</span>' : '<span class="pill pill-danger">Changed after signing</span>') : '') . '</p></footer>';
}

