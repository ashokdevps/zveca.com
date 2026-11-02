<?php
/**
 * HR Document Centre, signatories, employee induction & policies, account activation and e-mail delivery log (schema v24).
 *
 *   Templates     hrm_hr_templates (record) + hrm_hr_template_versions (every upload / edit; never overwritten)
 *   Documents     hrm_hr_documents: a recipient's own copy generated from one template version (frozen text, versions, status)
 *   Signatories   hrm_signatories: signature *images* placed on prepared documents (not an electronic signature)
 *   Induction     hrm_induction_items (+ _assignees, _acks); policy acknowledgements stay in hrm_policy_acks (one source of truth)
 *   Activation    it_login.activation_hash (SHA-256 of a one-time link token) → activate-account.php sets the password
 *   Mail log      hrm_mail_log: every important e-mail attempt (accepted by the mail server / failed), with a resend path
 *
 * Word handling (validation, DOCX → HTML, HTML → DOCX, sanitising) is shared with the client agreements (app/lib/documents.php).
 */

const HR_APPLIES_TO = ['All' => 'All employees', 'Fresher' => 'Freshers', 'Experienced' => 'Experienced hires', 'Employee' => 'Current employees', 'Exit' => 'Exiting employees'];
const HR_RESPONSES = ['none' => 'View only', 'acknowledge' => 'Acknowledge receipt', 'accept' => 'Accept or decline'];
const HR_DOC_STATUSES = ['Draft', 'Pending Approval', 'Approved', 'Shared', 'Viewed', 'Acknowledged', 'Accepted', 'Declined', 'Expired', 'Revoked', 'Superseded'];
const HR_DOC_EDITABLE = ['Draft', 'Pending Approval', 'Approved'];
const HR_DOC_OPEN = ['Shared', 'Viewed'];
/** The wording employees confirm for each policy (the brief's text). */
const POLICY_ACK_LABEL = 'I confirm that I have read and understood this policy and acknowledge that I am responsible for complying with the applicable company requirements.';
const HR_DOC_ACK_LABEL = 'I confirm that I have received and read this document.';
const HR_EVENT_LABELS = ['tpl_created' => 'Template uploaded', 'tpl_version' => 'New template version', 'tpl_edited' => 'Template text edited', 'tpl_status' => 'Template status changed',
    'tpl_approved' => 'Template approved', 'tpl_deleted' => 'Template deleted', 'tpl_meta' => 'Template details changed', 'generated' => 'Document generated', 'edited' => 'Document edited',
    'submitted' => 'Sent for approval', 'approved' => 'Approved', 'rejected' => 'Returned to draft', 'shared' => 'Shared with recipient', 'viewed' => 'Opened by recipient',
    'acknowledged' => 'Acknowledged by recipient', 'accepted' => 'Accepted by recipient', 'declined' => 'Declined by recipient', 'revoked' => 'Access revoked', 'version' => 'New version',
    'expired' => 'Expired', 'downloaded' => 'Downloaded', 'signature_inserted' => 'Signature image inserted', 'sig_added' => 'Signatory added', 'sig_changed' => 'Signatory changed',
    'emp_id' => 'Employee ID generated', 'activation' => 'Account activation link issued', 'activated' => 'Account activated', 'welcome' => 'Welcome e-mail', 'official_email' => 'Official e-mail recorded',
    'ind_item' => 'Induction item changed', 'ind_ack' => 'Induction acknowledged', 'ind_remind' => 'Reminder sent'];

/* ───────────────────────── Permissions ───────────────────────── */

function hrd_can_manage(): bool { return !is_external() && can('manage_hr_documents'); }
function hrd_can_approve(): bool { return !is_external() && (is_super_admin() || can('approve_hr_documents')); }
function hrd_can_induction(): bool { return !is_external() && can_any('manage_induction', 'manage_hr_documents'); }
function hrd_approval_required(): bool { return setting('hr_doc_require_approval', '0') === '1'; }

/* ───────────────────────── Audit & mail ───────────────────────── */

function hrd_event(array $ref, string $event, string $detail = ''): void
{
    insert('hrm_hr_doc_events', ['document_id' => $ref['document_id'] ?? null, 'template_id' => $ref['template_id'] ?? null, 'signatory_id' => $ref['signatory_id'] ?? null,
        'employee_id' => $ref['employee_id'] ?? null, 'event' => $event, 'detail' => $detail !== '' ? mb_substr($detail, 0, 500) : null,
        'login_id' => user()['id'] ?? null, 'ip' => PHP_SAPI === 'cli' ? null : client_ip()]);
}

/**
 * Sends an e-mail and records the attempt. "sent" means the mail server accepted it (delivery to the inbox cannot be confirmed
 * by PHP mail()); "failed" keeps the reason so HR can correct the address or retry.
 */
function mail_logged(string $purpose, ?string $refType, ?int $refId, string $to, string $subject, string $body): bool
{
    $ok = false; $err = null;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $err = 'Invalid e-mail address';
    else {
        try { $ok = send_mail($to, $subject, $body); if (!$ok) $err = 'The mail server did not accept the message'; }
        catch (Throwable $ex) { $err = mb_substr($ex->getMessage(), 0, 250); }
    }
    try {
        insert('hrm_mail_log', ['purpose' => $purpose, 'ref_type' => $refType, 'ref_id' => $refId, 'recipient' => mb_substr($to, 0, 190), 'subject' => mb_substr($subject, 0, 190),
            'status' => $ok ? 'sent' : 'failed', 'error' => $err, 'created_by' => user()['id'] ?? null]);
    } catch (Throwable $ex) { error_log('Mail log: ' . $ex->getMessage()); }
    return $ok;
}

/* ───────────────────────── Categories, types, templates ───────────────────────── */

function hrd_categories(bool $activeOnly = true): array
{
    return all('SELECT * FROM hrm_hr_doc_categories' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, name');
}

function hrd_types(bool $activeOnly = true): array
{
    return all('SELECT t.*, c.name category FROM hrm_hr_doc_types t JOIN hrm_hr_doc_categories c ON c.id = t.category_id' . ($activeOnly ? ' WHERE t.active = 1 AND c.active = 1' : '') . ' ORDER BY c.sort, t.sort, t.name');
}

function hrd_template(int $id): ?array
{
    return one('SELECT t.*, ty.name type_name, ty.response, ty.category_id, c.name category, v.file, v.file_format, v.original_name, v.content, v.id version_id, u.username creator, m.username modifier
                FROM hrm_hr_templates t JOIN hrm_hr_doc_types ty ON ty.id = t.type_id JOIN hrm_hr_doc_categories c ON c.id = ty.category_id
                LEFT JOIN hrm_hr_template_versions v ON v.template_id = t.id AND v.version = t.current_version
                LEFT JOIN it_login u ON u.id = t.created_by LEFT JOIN it_login m ON m.id = t.updated_by WHERE t.id = ? AND t.deleted_at IS NULL', [$id]);
}

/** Validates and stores an uploaded template file (DOCX / DOC / PDF). Returns [path, ext, html|null, original name, sha256]. */
function hrd_store_file(string $field, array $allowed = ['docx', 'doc', 'pdf']): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a file to upload.');
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('Allowed file types: ' . implode(', ', array_map('strtoupper', $allowed)) . '.');
    if ($f['error'] === UPLOAD_ERR_OK && in_array($ext, ['docx', 'doc'], true) && ($err = doc_word_error($f['tmp_name'], $ext))) throw new RuntimeException($err);
    $path = store_upload($field, 'hrdocs', max(1, (int) setting('hr_doc_max_mb', 10)));
    $abs = STORAGE_DIR . '/uploads/' . $path;
    $html = match ($ext) { 'docx' => docx_to_html($abs), 'doc' => doc_binary_preview($abs), default => null };
    return [$path, $ext, $html !== '' ? $html : null, mb_substr(basename(str_replace('\\', '/', (string) $f['name'])), 0, 190), hash_file('sha256', $abs)];
}

/** Adds a version (v1 for a new template) from an upload and / or edited text. Returns the version number. */
function hrd_add_version(int $templateId, ?array $file, ?string $html, string $note): int
{
    $v = (int) val('SELECT COALESCE(MAX(version), 0) FROM hrm_hr_template_versions WHERE template_id = ?', [$templateId]) + 1;
    $prev = $v > 1 ? one('SELECT * FROM hrm_hr_template_versions WHERE template_id = ? ORDER BY version DESC LIMIT 1', [$templateId]) : null;
    $row = ['template_id' => $templateId, 'version' => $v, 'note' => mb_substr($note, 0, 255) ?: null, 'uploaded_by' => user()['id'] ?? null];
    if ($file) {
        [$path, $ext, $fhtml, $orig, $sha] = $file;
        $row += ['file' => $path, 'file_format' => $ext, 'file_hash' => $sha, 'original_name' => $orig, 'content' => $html ?? $fhtml];
    } else {
        // Edited text only: the earlier file stays the reference of the previous version; this version is the HTML.
        $row += ['content' => $html, 'file' => null, 'file_format' => $prev ? null : null];
    }
    if ($row['content'] !== null) $row['content'] = doc_clean_html((string) $row['content']);
    insert('hrm_hr_template_versions', $row);
    update('hrm_hr_templates', ['current_version' => $v, 'updated_by' => user()['id'] ?? null, 'updated_at' => date('Y-m-d H:i:s'),
        'approval_status' => hrd_approval_required() ? 'Pending' : 'Not required', 'approved_by' => null, 'approved_at' => null], $templateId);
    return $v;
}

/* ───────────────────────── Placeholders ───────────────────────── */

const HR_PLACEHOLDERS = [
    'candidate_name' => 'Candidate / employee full name', 'first_name' => 'First name', 'personal_email' => 'Personal e-mail', 'phone' => 'Phone number',
    'designation' => 'Job title / designation', 'department' => 'Department', 'manager' => 'Reporting manager', 'work_location' => 'Work location',
    'joining_date' => 'Date of joining', 'employment_type' => 'Employment type', 'ctc' => 'Annual CTC', 'ctc_words' => 'CTC in words', 'monthly_gross' => 'Monthly gross',
    'salary_table' => 'Salary breakup table', 'probation_months' => 'Probation period (months)', 'working_hours' => 'Working hours', 'notice_period' => 'Notice period',
    'company_name' => 'Company name', 'company_address' => 'Company address', 'signatory_name' => 'Authorised signatory', 'signatory_designation' => 'Signatory designation',
    'signature' => 'Signature image', 'issue_date' => 'Issue date', 'acceptance_deadline' => 'Acceptance deadline', 'employee_id' => 'Employee ID', 'official_email' => 'Official e-mail',
    'offer_ref' => 'Offer reference', 'today' => "Today's date",
];

/**
 * Verified values for a recipient (null = not on record). Sources, in order: onboarding case, employee record, candidate record.
 * Nothing is guessed: missing values are flagged in the document for HR to fill in or edit.
 */
function hrd_context(?int $candidateId, ?int $employeeId, ?int $onboardingId, ?array $signatory = null): array
{
    $v = array_fill_keys(array_keys(HR_PLACEHOLDERS), null);
    $v['company_name'] = setting('company_name') ?: null;
    $v['company_address'] = setting('company_address') ?: null;
    $v['today'] = $v['issue_date'] = fdate(date('Y-m-d'), 'd F Y');
    $v['notice_period'] = setting('notice_period_days') ? setting('notice_period_days') . ' days' : null;
    $v['working_hours'] = setting('office_start') && setting('office_end') ? date('h:i A', strtotime(setting('office_start'))) . ' to ' . date('h:i A', strtotime(setting('office_end'))) . ', Monday to Friday' : null;
    $o = $onboardingId ? onb_find($onboardingId) : ($candidateId ? (($oid = val("SELECT id FROM hrm_onboardings WHERE candidate_id = ? AND status NOT IN ('Declined','Withdrawn') ORDER BY id DESC LIMIT 1", [$candidateId])) ? onb_find((int) $oid) : null) : null);
    if ($o) $v = array_merge($v, array_filter(onb_placeholder_values($o), fn($x) => $x !== null && $x !== ''));
    $e = $employeeId ? one('SELECT e.*, d.name dept, g.title des, CONCAT(m.first_name, " ", COALESCE(m.last_name, "")) mgr FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id
                            LEFT JOIN hrm_designations g ON g.id = e.designation_id LEFT JOIN hrm_employees m ON m.id = e.manager_id WHERE e.id = ?', [$employeeId]) : null;
    if ($e) {
        $name = trim($e['first_name'] . ' ' . ($e['last_name'] ?? ''));
        $v = array_merge($v, array_filter(['candidate_name' => $name, 'first_name' => $e['first_name'], 'personal_email' => $e['email'], 'phone' => $e['phone'], 'designation' => $e['des'],
            'department' => $e['dept'], 'manager' => trim((string) $e['mgr']) ?: null, 'work_location' => $e['work_location'], 'joining_date' => $e['join_date'] ? fdate($e['join_date'], 'd F Y') : null,
            'employment_type' => $e['employment_type'], 'ctc' => $e['ctc_annual'] ? money($e['ctc_annual']) : null, 'ctc_words' => $e['ctc_annual'] ? amount_in_words((float) $e['ctc_annual']) : null,
            'employee_id' => $e['emp_code'], 'official_email' => $e['official_email_status'] === 'Provisioned' ? $e['official_email'] : null], fn($x) => $x !== null && $x !== ''));
    }
    $c = $candidateId ? one('SELECT c.*, j.title job_title, j.location job_location, j.employment_type job_type FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id = ?', [$candidateId]) : null;
    if ($c) {
        foreach (['candidate_name' => $c['name'], 'first_name' => explode(' ', trim($c['name']))[0], 'personal_email' => $c['email'], 'phone' => $c['phone'], 'designation' => $c['job_title'],
                  'work_location' => $c['job_location'], 'employment_type' => $c['job_type']] as $k => $x) if ($v[$k] === null && $x) $v[$k] = $x;
    }
    if ($signatory) {
        $v['signatory_name'] = $signatory['name'];
        $v['signatory_designation'] = $signatory['designation'];
        $v['signature'] = sig_img_html($signatory) ?: null;
    }
    return $v;
}

/**
 * Replaces {{placeholders}} in template HTML. Missing values become a highlighted marker and are returned in $missing,
 * so HR fills them in (or edits the text) instead of a wrong value going out. salary_table and signature are HTML; others are escaped.
 */
function hrd_render(string $html, array $vals, ?array &$missing = null): string
{
    $missing = [];
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vals, &$missing) {
        $k = strtolower($m[1]);
        if (!array_key_exists($k, HR_PLACEHOLDERS) && !array_key_exists($k, $vals)) return $m[0];
        $val = $vals[$k] ?? null;
        if ($val === null || $val === '') {
            $missing[$k] = HR_PLACEHOLDERS[$k] ?? $k;
            return '<mark class="ph-missing" data-ph="' . e($k) . '">[' . e(HR_PLACEHOLDERS[$k] ?? $k) . ' missing]</mark>';
        }
        return in_array($k, ['salary_table', 'signature'], true) ? (string) $val : nl2br(e((string) $val));
    }, $html);
}

/** Missing-value markers still present in a document's text (HR has to resolve them before finalising). */
function hrd_missing_in(?string $html): array
{
    if (!$html) return [];
    // The marker text survives editing (the editor keeps text, not the <mark> tag), so the text itself is checked too.
    $labels = array_flip(HR_PLACEHOLDERS);
    preg_match_all('/\[([^\[\]<>]{2,80}) missing\]/u', strip_tags($html), $m);
    $out = array_filter($m[1], fn($l) => isset($labels[$l]) || preg_match('/^[a-z_]+$/', $l));
    if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $html, $t)) foreach ($t[1] as $k) if (isset(HR_PLACEHOLDERS[strtolower($k)])) $out[] = HR_PLACEHOLDERS[strtolower($k)];
    return array_values(array_unique($out));
}

/* ───────────────────────── Signatories ───────────────────────── */

function sig_options(): array
{
    return array_column(all("SELECT id, CONCAT(name, ' — ', designation) l FROM hrm_signatories WHERE active = 1 ORDER BY name"), 'l', 'id');
}

function sig_find(?int $id, bool $activeOnly = true): ?array
{
    return $id ? one('SELECT * FROM hrm_signatories WHERE id = ?' . ($activeOnly ? ' AND active = 1' : ''), [$id]) : null;
}

/** The signature image embedded in the text (data URI), so a later replacement never changes issued documents. */
function sig_img_html(array $s): string
{
    $abs = $s['image'] ? STORAGE_DIR . '/uploads/' . $s['image'] : '';
    if (!$abs || !is_file($abs) || filesize($abs) > 600 * 1024) return '';
    $mime = str_ends_with(strtolower($abs), '.png') ? 'image/png' : 'image/jpeg';
    return '<img class="sig-image" src="data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($abs)) . '" alt="Signature of ' . e($s['name']) . '">';
}

/** Signature block appended to a letter that has no {{signature}} placeholder. */
function sig_block(array $s): string
{
    return '<div class="sig-block"><p>For ' . e(setting('company_name')) . '</p>' . sig_img_html($s) . '<p><strong>' . e($s['name']) . '</strong><br>' . e($s['designation']) . '</p></div>';
}

/* ───────────────────────── Generated (recipient) documents ───────────────────────── */

function hrd_doc(int $id): ?array
{
    return one('SELECT d.*, t.name template_name, ty.name type_name, c.name cand_name, c.email cand_email, CONCAT(e.first_name, " ", COALESCE(e.last_name, "")) emp_name, e.emp_code, e.email emp_email,
                       u.username creator, s.name signatory_name
                FROM hrm_hr_documents d LEFT JOIN hrm_hr_templates t ON t.id = d.template_id LEFT JOIN hrm_hr_doc_types ty ON ty.id = d.type_id
                LEFT JOIN hrm_candidates c ON c.id = d.candidate_id LEFT JOIN hrm_employees e ON e.id = d.employee_id LEFT JOIN it_login u ON u.id = d.created_by
                LEFT JOIN hrm_signatories s ON s.id = d.signatory_id WHERE d.id = ?', [$id]);
}

/** Is the signed-in user the recipient of this document? (their own login, or their employee record) */
function hrd_is_recipient(array $d): bool
{
    $u = user();
    if (!$u) return false;
    if ($d['recipient_login_id'] && (int) $d['recipient_login_id'] === (int) $u['id']) return true;
    return $d['employee_id'] && my_employee_id() && (int) $d['employee_id'] === (int) my_employee_id() && !is_external();
}

/** view · edit · submit · approve · share · revoke · version · download (staff) — view · download · respond (recipient) */
function hrd_doc_can(array $d, string $act): bool
{
    $status = $d['status'];
    if (hrd_can_manage() || hrd_can_approve()) {
        $mgr = hrd_can_manage();
        return match ($act) {
            'view', 'download' => true,
            'edit' => $mgr && in_array($status, HR_DOC_EDITABLE, true),
            'submit' => $mgr && $status === 'Draft' && hrd_approval_required(),
            'approve' => hrd_can_approve() && $status === 'Pending Approval' && ((int) $d['created_by'] !== (int) (user()['id'] ?? null) || is_super_admin()),
            'share' => $mgr && ($status === 'Approved' || ($status === 'Draft' && !hrd_approval_required())),
            'revoke' => $mgr && in_array($status, ['Shared', 'Viewed', 'Acknowledged', 'Accepted'], true),
            'version' => $mgr && !in_array($status, ['Superseded', 'Draft', 'Pending Approval'], true),
            default => false,
        };
    }
    if (!hrd_is_recipient($d) || !in_array($status, ['Shared', 'Viewed', 'Acknowledged', 'Accepted', 'Declined', 'Expired'], true)) return false;
    return match ($act) {
        'view' => true,
        'download' => (bool) $d['allow_download'],
        'respond' => in_array($status, HR_DOC_OPEN, true) && $d['response'] !== 'none' && (!$d['expires_at'] || strtotime($d['expires_at']) > time()),
        default => false,
    };
}

function hrd_doc_require(array $d, string $act): void
{
    if (!hrd_doc_can($d, $act)) authorize('__never__');
}

/** The portal login a document will be shared with: the employee's login, else the candidate's (onboarding or careers account). */
function hrd_recipient_login(?int $candidateId, ?int $employeeId): ?array
{
    if ($employeeId && ($l = one("SELECT id, email, username FROM it_login WHERE employee_id = ? AND status = 'active' ORDER BY id LIMIT 1", [$employeeId]))) return $l;
    if ($candidateId) {
        $lid = val("SELECT login_id FROM hrm_onboardings WHERE candidate_id = ? AND login_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$candidateId])
            ?: val('SELECT t.login_id FROM hrm_candidates c JOIN hrm_talent t ON t.id = c.talent_id WHERE c.id = ? AND t.login_id IS NOT NULL', [$candidateId]);
        if ($lid && ($l = one("SELECT id, email, username FROM it_login WHERE id = ? AND status = 'active'", [$lid]))) return $l;
    }
    return null;
}

/** Generates a recipient copy of a template's current version (Draft). */
function hrd_generate(array $t, ?int $candidateId, ?int $employeeId, ?int $signatoryId, string $title = ''): int
{
    if (!$candidateId && !$employeeId) throw new RuntimeException('Choose the candidate or employee.');
    if ($t['status'] !== 'Active') throw new RuntimeException('This template is inactive.');
    if ($t['approval_status'] === 'Pending') throw new RuntimeException('This template version is waiting for approval.');
    $sig = $signatoryId ? sig_find($signatoryId) : null;
    if ($signatoryId && !$sig) throw new RuntimeException('Choose an active signatory.');
    $onb = $candidateId ? val("SELECT id FROM hrm_onboardings WHERE candidate_id = ? AND status NOT IN ('Declined','Withdrawn') ORDER BY id DESC LIMIT 1", [$candidateId]) : null;
    $content = null;
    if ($t['content'] !== null) {
        $content = hrd_render((string) $t['content'], hrd_context($candidateId, $employeeId, $onb ? (int) $onb : null, $sig));
        if ($sig && !str_contains((string) $t['content'], '{{signature}}')) $content .= sig_block($sig);
    }
    $name = $employeeId ? (string) val('SELECT CONCAT(first_name, " ", COALESCE(last_name, "")) FROM hrm_employees WHERE id = ?', [$employeeId]) : (string) val('SELECT name FROM hrm_candidates WHERE id = ?', [$candidateId]);
    $id = insert('hrm_hr_documents', ['template_id' => $t['id'], 'template_version' => $t['current_version'], 'type_id' => $t['type_id'],
        'title' => mb_substr($title !== '' ? $title : $t['type_name'] . ' — ' . trim($name), 0, 190), 'candidate_id' => $candidateId, 'employee_id' => $employeeId,
        'onboarding_id' => $onb ?: null, 'content' => $content, 'content_hash' => $content !== null ? hash('sha256', $content) : null,
        'file' => $content === null ? $t['file'] : null, 'status' => 'Draft', 'response' => $t['response'], 'signatory_id' => $sig['id'] ?? null,
        'created_by' => user()['id'] ?? null, 'updated_at' => date('Y-m-d H:i:s')]);
    hrd_event(['document_id' => $id, 'template_id' => $t['id'], 'employee_id' => $employeeId], 'generated', $t['name'] . ' v' . $t['current_version']);
    if ($sig) hrd_event(['document_id' => $id, 'signatory_id' => $sig['id']], 'signature_inserted', $sig['name'] . ' (' . $sig['designation'] . ') · image placed on the document; not an electronic signature');
    return $id;
}

/** Saves edits to a not-yet-shared document (approved documents go back to draft when approval is required). */
function hrd_save_content(array $d, string $html, string $note = ''): void
{
    if (!in_array($d['status'], HR_DOC_EDITABLE, true)) throw new RuntimeException('This document has been shared. Create a new version to change it.');
    // The signature image placed at generation is kept as it was (the sanitiser removes images; the browser cannot supply a new one).
    $orig = preg_match('/<img[^>]*class="sig-image"[^>]*>/', (string) $d['content'], $m) ? $m[0] : null;
    $html = preg_replace('/<img[^>]*class="sig-image"[^>]*>/', '%%SIGNATURE%%', $html);
    $html = str_replace('%%SIGNATURE%%', $orig ?? '', doc_clean_html($html));
    if (trim(strip_tags($html)) === '') throw new RuntimeException('The document is empty.');
    $back = hrd_approval_required() && $d['status'] !== 'Draft';
    update('hrm_hr_documents', ['content' => $html, 'content_hash' => hash('sha256', $html), 'updated_by' => (user()['id'] ?? null), 'updated_at' => date('Y-m-d H:i:s'),
        'status' => $back ? 'Draft' : $d['status'], 'approved_by' => $back ? null : $d['approved_by'], 'approved_at' => $back ? null : $d['approved_at']], (int) $d['id']);
    hrd_event(['document_id' => $d['id']], 'edited', $note ?: 'Version ' . $d['version']);
}

/** New version of a shared document: the shared copy stays as it was (Superseded) and loses its recipient access. */
function hrd_new_version(array $d): int
{
    $copy = array_intersect_key($d, array_flip(['template_id', 'template_version', 'type_id', 'title', 'candidate_id', 'employee_id', 'onboarding_id', 'content', 'file', 'response', 'signatory_id', 'allow_download']));
    $id = insert('hrm_hr_documents', $copy + ['content_hash' => $d['content'] !== null ? hash('sha256', (string) $d['content']) : null, 'version' => (int) $d['version'] + 1, 'supersedes_id' => $d['id'],
        'status' => 'Draft', 'created_by' => (user()['id'] ?? null), 'updated_at' => date('Y-m-d H:i:s')]);
    $keep = in_array($d['status'], ['Acknowledged', 'Accepted', 'Declined'], true); // the recipient's response stays on record
    q('UPDATE hrm_hr_documents SET superseded_by = ?, status = ? WHERE id = ?', [$id, $keep ? $d['status'] : 'Superseded', $d['id']]);
    hrd_event(['document_id' => $d['id']], 'version', 'Replaced by version ' . ((int) $d['version'] + 1));
    hrd_event(['document_id' => $id], 'version', 'Version ' . ((int) $d['version'] + 1) . ' created from version ' . $d['version']);
    return $id;
}

/** Shares a finalised document with its recipient's portal and e-mails a sign-in link (never the document itself). */
function hrd_share(array $d, int $days, bool $download): void
{
    if ($missing = hrd_missing_in($d['content'])) throw new RuntimeException('Fill in or remove the missing details first: ' . implode(', ', $missing) . '.');
    if ($d['content'] === null && !$d['file']) throw new RuntimeException('The document has no content.');
    $login = hrd_recipient_login($d['candidate_id'] ? (int) $d['candidate_id'] : null, $d['employee_id'] ? (int) $d['employee_id'] : null);
    if (!$login || !filter_var((string) $login['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('This recipient has no active portal login with an e-mail yet. Employees get one at activation; candidates when they open their onboarding link or create a careers profile.');
    }
    $days = max(0, min(180, $days));
    update('hrm_hr_documents', ['status' => 'Shared', 'recipient_login_id' => $login['id'], 'recipient_email' => $login['email'], 'shared_by' => (user()['id'] ?? null), 'shared_at' => date('Y-m-d H:i:s'),
        'viewed_at' => null, 'expires_at' => $days ? date('Y-m-d 23:59:59', strtotime("+$days days")) : null, 'allow_download' => $download ? 1 : 0,
        'content_hash' => $d['content'] !== null ? hash('sha256', (string) $d['content']) : null, 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
    $link = site_origin() . url('mydocs', ['a' => 'doc', 'id' => $d['id']]);
    $need = ['none' => 'Please read it.', 'acknowledge' => 'Please read it and confirm that you have received it.', 'accept' => 'Please read it and accept or decline it.'][$d['response']] ?? '';
    $sent = mail_logged('doc_shared', 'hr_document', (int) $d['id'], (string) $login['email'], 'Document shared with you: ' . $d['title'],
        "Hello,\n\n" . setting('company_name') . " has shared \"{$d['title']}\" with you. $need\n\nOpen it securely (sign in with your HRMS account):\n$link\n"
        . ($days ? "\nPlease respond by " . fdate(date('Y-m-d', strtotime("+$days days")), 'd M Y') . ".\n" : '') . "\n— " . setting('company_name') . ' HR');
    notify((int) $login['id'], 'document', 'Document shared with you: ' . $d['title'], $need, url('mydocs', ['a' => 'doc', 'id' => $d['id']]), 'ri-file-text-line', 'info', 'hrdshare:' . $d['id']);
    hrd_event(['document_id' => $d['id'], 'employee_id' => $d['employee_id']], 'shared', 'To ' . mask_email((string) $login['email']) . ($sent ? ' · e-mail accepted by the mail server' : ' · e-mail FAILED (visible in the portal)'));
}

function hrd_mark_viewed(array $d): void
{
    if (hrd_is_recipient($d) && $d['status'] === 'Shared') {
        q("UPDATE hrm_hr_documents SET status = 'Viewed', viewed_at = NOW() WHERE id = ? AND status = 'Shared'", [$d['id']]);
        hrd_event(['document_id' => $d['id'], 'employee_id' => $d['employee_id']], 'viewed');
    }
}

/** Recipient response: acknowledge / accept / decline (recorded with time, IP and the signed-in identity). */
function hrd_respond(array $d, string $kind, string $note): void
{
    $status = ['acknowledge' => 'Acknowledged', 'accept' => 'Accepted', 'decline' => 'Declined'][$kind] ?? null;
    if (!$status || ($d['response'] === 'acknowledge' && $kind !== 'acknowledge') || ($d['response'] === 'accept' && $kind === 'acknowledge')) throw new RuntimeException('That response is not available for this document.');
    update('hrm_hr_documents', ['status' => $status, 'responded_at' => date('Y-m-d H:i:s'), 'response_note' => mb_substr($note, 0, 500) ?: null, 'response_ip' => client_ip()], (int) $d['id']);
    hrd_event(['document_id' => $d['id'], 'employee_id' => $d['employee_id']], strtolower($status), trim('By ' . (user()['username'] ?? '') . ($note !== '' ? " · $note" : '')));
    notify(array_merge([(int) $d['created_by']], logins_with('manage_hr_documents', false)), 'document', "$status: {$d['title']}", ($d['emp_name'] ?: $d['cand_name']) . ($note !== '' ? " · $note" : ''), url('hrdocs', ['a' => 'doc', 'id' => $d['id']]), $status === 'Declined' ? 'ri-close-circle-line' : 'ri-checkbox-circle-line', $status === 'Declined' ? 'danger' : 'success', 'hrdresp:' . $d['id'] . ':' . $d['version']);
    if ($to = setting('onboarding_hr_email') ?: setting('company_email')) {
        mail_logged('doc_response', 'hr_document', (int) $d['id'], $to, "$status: {$d['title']}", ($d['emp_name'] ?: $d['cand_name']) . " $status \"{$d['title']}\" (version {$d['version']})." . ($note !== '' ? "\nNote: $note" : '')
            . "\n\n" . site_origin() . url('hrdocs', ['a' => 'doc', 'id' => $d['id']]));
    }
}

/** Expired requests (checked on page loads, throttled; also app/cron.php). */
function hrd_tick(bool $force = false): int
{
    if (!$force) {
        if (time() - (int) setting('hrd_tick_at', 0) < 1800) return 0;
        set_setting('hrd_tick_at', time());
    }
    $n = 0;
    foreach (all("SELECT id FROM hrm_hr_documents WHERE status IN ('Shared','Viewed') AND expires_at IS NOT NULL AND expires_at < NOW() LIMIT 200") as $r) {
        q("UPDATE hrm_hr_documents SET status = 'Expired' WHERE id = ?", [$r['id']]);
        hrd_event(['document_id' => $r['id']], 'expired', 'No response before the deadline');
        $n++;
    }
    return $n;
}

/**
 * Document history of a candidate / employee for HR: generated HR documents, the offer letter(s) and e-signed onboarding
 * documents. Rows: kind, title, version, created, shared, status, responded, signature, link.
 */
function hrd_history(?int $candidateId, ?int $employeeId): array
{
    $rows = [];
    $w = []; $p = [];
    if ($candidateId) { $w[] = 'd.candidate_id = ?'; $p[] = $candidateId; }
    if ($employeeId) { $w[] = 'd.employee_id = ?'; $p[] = $employeeId; }
    if ($w) foreach (all('SELECT d.*, ty.name type_name FROM hrm_hr_documents d LEFT JOIN hrm_hr_doc_types ty ON ty.id = d.type_id WHERE ' . implode(' OR ', $w) . ' ORDER BY d.id DESC', $p) as $d) {
        $rows[] = ['kind' => $d['type_name'] ?: 'Document', 'title' => $d['title'], 'version' => (int) $d['version'], 'created' => $d['created_at'], 'shared' => $d['shared_at'], 'recipient' => $d['recipient_email'],
            'status' => $d['status'], 'responded' => $d['responded_at'], 'signature' => $d['signatory_id'] ? 'Signature image (signatory)' : null, 'url' => url('hrdocs', ['a' => 'doc', 'id' => $d['id']])];
    }
    $ow = []; $op = [];
    if ($candidateId) { $ow[] = 'candidate_id = ?'; $op[] = $candidateId; }
    if ($employeeId) { $ow[] = 'employee_id = ?'; $op[] = $employeeId; }
    if ($ow) foreach (all('SELECT * FROM hrm_onboardings WHERE ' . implode(' OR ', $ow) . ' ORDER BY id DESC', $op) as $o) {
        $acc = val("SELECT signed_at FROM hrm_onboarding_signatures WHERE onboarding_id = ? AND kind = 'offer' ORDER BY id DESC LIMIT 1", [$o['id']]);
        $rows[] = ['kind' => 'Offer Letter', 'title' => 'Offer letter ' . ($o['offer_ref'] ?: '(draft)'), 'version' => (int) $o['revision'], 'created' => $o['created_at'], 'shared' => $o['sent_at'],
            'recipient' => $o['email'], 'status' => $o['status'], 'responded' => $o['accepted_at'], 'signature' => $acc ? 'E-signed by candidate (OTP + drawn) ' . fdate($acc, 'd M Y') : null,
            'url' => url('onboarding', ['a' => 'letter', 'id' => $o['id']])];
        foreach (all("SELECT id, title, version, signed_at, signed_name FROM hrm_onboarding_signatures WHERE onboarding_id = ? AND kind <> 'offer' ORDER BY id", [$o['id']]) as $s) {
            $rows[] = ['kind' => 'E-signed onboarding document', 'title' => $s['title'], 'version' => (int) $s['version'], 'created' => $s['signed_at'], 'shared' => null, 'recipient' => $o['email'],
                'status' => 'Signed', 'responded' => $s['signed_at'], 'signature' => 'E-signed by ' . $s['signed_name'], 'url' => url('onboarding', ['a' => 'signed', 'id' => $s['id']])];
        }
    }
    usort($rows, fn($a, $b) => strcmp((string) $b['created'], (string) $a['created']));
    return $rows;
}

/** History card (candidate / employee profile, HR only). */
function hrd_history_card(?int $candidateId, ?int $employeeId): string
{
    if (!hrd_can_manage() && !can_any('manage_onboarding', 'view_employees', 'manage_recruitment')) return '';
    $rows = hrd_history($candidateId, $employeeId);
    $new = hrd_can_manage() ? '<a class="btn btn-sm btn-light border" href="' . e(url('hrdocs', array_filter(['a' => 'new', 'candidate_id' => $candidateId, 'employee_id' => $employeeId]))) . '"><i class="ri-file-add-line me-1"></i>Prepare document</a>' : '';
    $h = '<div class="card mt-3"><div class="card-header-lite"><span><i class="ri-folder-shield-2-line text-primary me-1"></i>Document history</span>' . $new . '</div>';
    if (!$rows) return $h . '<div class="card-body small text-muted">No documents yet.</div></div>';
    $h .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Document</th><th>Type</th><th>Ver.</th><th>Created</th><th>Shared</th><th>Recipient</th><th>Status</th><th>Response</th><th>Signature</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td class="fw-semibold small">' . e($r['title']) . '</td><td class="small">' . e($r['kind']) . '</td><td>v' . $r['version'] . '</td><td class="small text-nowrap">' . fdate($r['created'], 'd M Y') . '</td>'
            . '<td class="small text-nowrap">' . ($r['shared'] ? fdate($r['shared'], 'd M Y') : '—') . '</td><td class="small">' . e($r['recipient'] ? mask_email($r['recipient']) : '—') . '</td><td>' . badge($r['status']) . '</td>'
            . '<td class="small text-nowrap">' . ($r['responded'] ? fdate($r['responded'], 'd M Y') : '—') . '</td><td class="small">' . e($r['signature'] ?? '—') . '</td>'
            . '<td class="text-end"><a class="btn btn-sm btn-icon" href="' . e($r['url']) . '" title="Open" aria-label="Open"><i class="ri-eye-line"></i></a></td></tr>';
    }
    return $h . '</tbody></table></div></div>';
}

/* ───────────────────────── Induction & policies ───────────────────────── */

/** Active induction items that apply to an employee (audience, assignment, cohort, effective date). */
function ind_items_for(int $empId): array
{
    $e = one('SELECT id, department_id, join_date FROM hrm_employees WHERE id = ?', [$empId]);
    if (!$e) return [];
    return all("SELECT i.*, p.title policy_title, p.version policy_version, p.status policy_status FROM hrm_induction_items i LEFT JOIN hrm_policies p ON p.id = i.policy_id
                WHERE i.active = 1 AND (i.effective_from IS NULL OR i.effective_from <= CURDATE()) AND (i.kind <> 'policy' OR p.status = 'Published') AND (
                  i.audience = 'all'
                  OR (i.audience = 'department' AND i.department_id <=> ?)
                  OR (i.audience = 'employees' AND EXISTS (SELECT 1 FROM hrm_induction_assignees a WHERE a.item_id = i.id AND a.employee_id = ?))
                  OR (i.audience = 'cohort' AND ? IS NOT NULL AND (i.cohort_from IS NULL OR ? >= i.cohort_from) AND (i.cohort_to IS NULL OR ? <= i.cohort_to)))
                ORDER BY i.mandatory DESC, i.sort, i.id", [$e['department_id'], $empId, $e['join_date'], $e['join_date'], $e['join_date']]);
}

/** The version an employee must acknowledge (policies: the policy's own version). */
function ind_version(array $i): string
{
    return $i['kind'] === 'policy' ? (string) $i['policy_version'] : (string) $i['version'];
}

/** Items with their state for an employee, plus totals. 'done' = acknowledged / completed for the current version. */
function ind_status(int $empId): array
{
    $items = ind_items_for($empId);
    $join = val('SELECT join_date FROM hrm_employees WHERE id = ?', [$empId]);
    $mand = $done = 0;
    foreach ($items as &$i) {
        $ver = ind_version($i);
        $row = one('SELECT opened_at, acknowledged_at FROM hrm_induction_acks WHERE item_id = ? AND item_version = ? AND employee_id = ?', [$i['id'], $ver, $empId]);
        $i['opened_at'] = $row['opened_at'] ?? null;
        $i['done_at'] = $i['kind'] === 'policy'
            ? val('SELECT acknowledged_at FROM hrm_policy_acks WHERE policy_id = ? AND employee_id = ? AND version = ?', [$i['policy_id'], $empId, $ver])
            : ($row['acknowledged_at'] ?? null);
        $i['older_ack'] = !$i['done_at'] && (bool) ($i['kind'] === 'policy'
            ? val('SELECT 1 FROM hrm_policy_acks WHERE policy_id = ? AND employee_id = ? AND version <> ?', [$i['policy_id'], $empId, $ver])
            : val('SELECT 1 FROM hrm_induction_acks WHERE item_id = ? AND employee_id = ? AND item_version <> ? AND acknowledged_at IS NOT NULL', [$i['id'], $empId, $ver]));
        $i['due'] = $i['due_days'] !== null && $join ? date('Y-m-d', strtotime($join . ' +' . (int) $i['due_days'] . ' days')) : null;
        if ($i['mandatory']) { $mand++; if ($i['done_at']) $done++; }
    }
    unset($i);
    return ['items' => $items, 'mandatory' => $mand, 'done' => $done, 'complete' => $done === $mand, 'percent' => $mand ? (int) round($done * 100 / $mand) : 100];
}

function ind_item(int $id): ?array
{
    return one('SELECT i.*, p.title policy_title, p.version policy_version, p.status policy_status, p.content policy_content, p.attachment policy_attachment FROM hrm_induction_items i LEFT JOIN hrm_policies p ON p.id = i.policy_id WHERE i.id = ?', [$id]);
}

/** Records the first open of an item (opening never counts as acknowledging). */
function ind_open(int $empId, array $i): void
{
    q('INSERT INTO hrm_induction_acks (item_id, item_version, employee_id, login_id, opened_at, ip, user_agent) VALUES (?, ?, ?, ?, NOW(), ?, ?)
       ON DUPLICATE KEY UPDATE opened_at = COALESCE(opened_at, VALUES(opened_at))', [$i['id'], ind_version($i), $empId, user()['id'] ?? null, client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
}

/** Acknowledges an item for the signed-in employee only. Policies are recorded in hrm_policy_acks (and mirrored per item). */
function ind_ack(int $empId, array $i, bool $checked): void
{
    if (!$checked) throw new RuntimeException('Tick the confirmation box to acknowledge.');
    if ($empId !== (int) my_employee_id()) throw new RuntimeException('You can only acknowledge your own induction items.');
    $ver = ind_version($i);
    if ($i['kind'] === 'policy') {
        q('INSERT IGNORE INTO hrm_policy_acks (policy_id, employee_id, version, acknowledged_at, ip) VALUES (?, ?, ?, NOW(), ?)', [$i['policy_id'], $empId, $ver, client_ip()]);
    }
    q('INSERT INTO hrm_induction_acks (item_id, item_version, employee_id, login_id, opened_at, acknowledged_at, ip, user_agent) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?)
       ON DUPLICATE KEY UPDATE acknowledged_at = COALESCE(acknowledged_at, NOW()), login_id = VALUES(login_id), ip = VALUES(ip), user_agent = VALUES(user_agent)',
        [$i['id'], $ver, $empId, (user()['id'] ?? null), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    hrd_event(['employee_id' => $empId], 'ind_ack', $i['title'] . ' v' . $ver);
}

/** Employees the induction applies to with their completion (HR report). Filters: q, department_id, status (pending|complete), joined_from/to. */
function ind_report(array $f = []): array
{
    $w = ["e.status IN ('Active','On Probation','Notice Period')"]; $p = [];
    if (!empty($f['department_id'])) { $w[] = 'e.department_id = ?'; $p[] = (int) $f['department_id']; }
    if (!empty($f['joined_from'])) { $w[] = 'e.join_date >= ?'; $p[] = $f['joined_from']; }
    if (!empty($f['joined_to'])) { $w[] = 'e.join_date <= ?'; $p[] = $f['joined_to']; }
    if (!empty($f['q'])) { $w[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.emp_code LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
    $rows = [];
    foreach (all('SELECT e.id, e.emp_code, e.first_name, e.last_name, e.join_date, e.email, d.name dept FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id WHERE ' . implode(' AND ', $w) . ' ORDER BY e.join_date DESC, e.id DESC LIMIT 2000', $p) as $e) {
        $s = ind_status((int) $e['id']);
        if (!$s['items']) continue;
        $pending = array_values(array_filter($s['items'], fn($i) => $i['mandatory'] && !$i['done_at']));
        if (($f['status'] ?? '') === 'pending' && !$pending) continue;
        if (($f['status'] ?? '') === 'complete' && $pending) continue;
        $rows[] = $e + ['mandatory' => $s['mandatory'], 'done' => $s['done'], 'percent' => $s['percent'], 'pending' => array_column($pending, 'title'),
            'overdue' => count(array_filter($pending, fn($i) => $i['due'] && $i['due'] < date('Y-m-d')))];
    }
    return $rows;
}

/** E-mails employees with outstanding mandatory items (once per employee per day). Returns the number reminded. */
function ind_remind(array $empIds): int
{
    $n = 0;
    foreach (array_unique(array_map('intval', $empIds)) as $id) {
        $s = ind_status($id);
        $pending = array_filter($s['items'], fn($i) => $i['mandatory'] && !$i['done_at']);
        if (!$pending) continue;
        try { insert('hrm_notification_log', ['k' => "indremind:$id:" . date('Ymd')]); } catch (PDOException $e) { continue; }
        $to = val("SELECT email FROM it_login WHERE employee_id = ? AND status = 'active' ORDER BY id LIMIT 1", [$id]) ?: val('SELECT email FROM hrm_employees WHERE id = ?', [$id]);
        if (!$to) continue;
        notify((int) val("SELECT id FROM it_login WHERE employee_id = ? AND status = 'active' ORDER BY id LIMIT 1", [$id]), 'induction', 'Complete your induction', count($pending) . ' mandatory item(s) are waiting for you.', url('mydocs'), 'ri-book-open-line', 'warning', "indremind:$id:" . date('Ymd'));
        if (mail_logged('induction_reminder', 'employee', $id, $to, 'Reminder: complete your induction', "Hello,\n\nYou have " . count($pending) . " mandatory induction item(s) to complete:\n- "
            . implode("\n- ", array_column($pending, 'title')) . "\n\nOpen them here (sign in first): " . site_origin() . url('mydocs') . "\n\n— " . setting('company_name') . ' HR')) {
            hrd_event(['employee_id' => $id], 'ind_remind', count($pending) . ' item(s)');
            $n++;
        }
    }
    return $n;
}

/* ───────────────────────── Account activation (no passwords by e-mail) ───────────────────────── */

/** Issues a one-time activation link for a login; only the SHA-256 of the token is stored. Returns the full URL. */
function login_activation_link(int $loginId): string
{
    $token = bin2hex(random_bytes(32));
    $hours = max(1, (int) setting('activation_link_hours', 72));
    q('UPDATE it_login SET activation_hash = ?, activation_expires = ? WHERE id = ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() + $hours * 3600), $loginId]);
    return site_origin() . root_url('activate-account') . '?t=' . $token;
}

function login_by_activation(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return one("SELECT * FROM it_login WHERE activation_hash = ? AND activation_expires > NOW() AND status = 'active'", [hash('sha256', $token)]);
}

/**
 * Welcome / credentials e-mail for an activated employee, to the personal e-mail on the onboarding case (from the candidate
 * profile). Never contains a password: either "sign in with the password you created during onboarding" or a one-time setup link.
 * $confirmUnverified: HR confirmed an address that was never verified by an e-mail code.
 */
function onb_send_welcome(array $o, bool $confirmUnverified = false, bool $forceLink = false): bool
{
    $emp = $o['employee_id'] ? one('SELECT * FROM hrm_employees WHERE id = ?', [$o['employee_id']]) : null;
    if (!$emp) throw new RuntimeException('The employee record has not been created yet.');
    $to = strtolower(trim((string) $o['email']));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The personal e-mail on this onboarding case is not valid. Correct it before sending.');
    if (!$o['email_verified_at'] && !$confirmUnverified) throw new RuntimeException('This personal e-mail was never verified with a one-time code. Confirm that it is correct to send the credentials.');
    $login = one('SELECT * FROM it_login WHERE employee_id = ? ORDER BY id LIMIT 1', [$emp['id']]);
    if (!$login) throw new RuntimeException('The employee has no HRMS login.');
    $choseOwn = $o['login_id'] && (int) $o['login_id'] === (int) $login['id'] && $o['email_verified_at']; // created a password on the onboarding link
    $signIn = site_origin() . root_url('sign-in');
    $access = $choseOwn && !$forceLink
        ? "Sign in with your e-mail ($to) and the password you created during onboarding:\n$signIn\nForgot it? Use \"Forgot password\" on that page to get a one-time code by e-mail."
        : "Set your password with this secure one-time link (valid for " . (int) setting('activation_link_hours', 72) . " hours):\n" . login_activation_link((int) $login['id'])
          . "\n\nAfterwards sign in at $signIn with the e-mail $to.";
    $official = $emp['official_email'] && $emp['official_email_status'] === 'Provisioned' ? "\nOfficial e-mail: {$emp['official_email']}" : "\nOfficial e-mail: being set up by our IT team (we'll let you know).";
    $support = setting('onboarding_support_contact') ?: (setting('company_email') ? 'Questions? Write to ' . setting('company_email') . '.' : '');
    $body = "Hello {$emp['first_name']},\n\nWelcome to " . setting('company_name') . "! Your employee account is ready.\n\nEmployee ID: {$emp['emp_code']}$official\nDate of joining: " . fdate($emp['join_date'], 'd F Y')
        . "\n\n$access\n\nAfter signing in, open \"Induction & Documents\" and complete:\n1. Your induction items and company policies (each needs your acknowledgement)\n2. Any documents shared with you\n\n"
        . "Never share your password. HR will never ask for it.\n$support\n\n— " . setting('company_name') . ' HR';
    $ok = mail_logged('welcome', 'onboarding', (int) $o['id'], $to, 'Welcome to ' . setting('company_name') . " — your employee ID is {$emp['emp_code']}", $body);
    q('UPDATE hrm_onboardings SET welcome_status = ?, welcome_sent_at = ?, welcome_attempts = welcome_attempts + 1, welcome_error = ? WHERE id = ?',
        [$ok ? 'Sent' : 'Failed', $ok ? date('Y-m-d H:i:s') : null, $ok ? null : 'The mail server did not accept the message', $o['id']]);
    onb_log((int) $o['id'], 'welcome_email', ($ok ? 'Welcome e-mail sent to ' : 'Welcome e-mail FAILED for ') . mask_email($to) . ($choseOwn ? ' (own password)' : ' (activation link)'), user() ? 'hr' : 'system');
    hrd_event(['employee_id' => $emp['id']], 'welcome', ($ok ? 'Sent' : 'Failed') . ' to ' . mask_email($to));
    return $ok;
}
