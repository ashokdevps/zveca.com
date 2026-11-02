<?php
/**
 * Onboarding (see app/lib/onboarding.php for the workflow).
 *
 * HR (manage_onboarding; approve_offers for approvers)
 *   index                    cases by status          create / edit       offer details (final selection → offer)
 *   view (tabs)              case page: overview, candidate details, documents, e-signatures, verification, joining
 *   submit_approval, approve, request_changes, send, resend, revise, withdraw       offer workflow (POST)
 *   doc_verify, doc_reject, request_corrections, compliance, final_approve, joining  HR verification & activation (POST)
 *   templates, template      offer letter / agreement / NDA templates
 * Candidate portal (the case's login: candidate, later the same login as employee)
 *   me (?step=)              offer, personal, education, bank, documents, esign, submit
 *   accept, decline, save, upload, sign, submit                                      (POST)
 * Both
 *   letter (offer letter), signed (one e-signed document with its audit trail), print (joining form)
 */

if (!plan_allows('manage_onboarding')) {
    if (is_external()) redirect(url());
    plan_locked_page('manage_onboarding');
}

$isStaff = !is_external() && can_any('manage_onboarding', 'approve_offers');
$canManage = !is_external() && can('manage_onboarding');
$a = $_GET['a'] ?? ($isStaff ? 'index' : 'me');
$hrActions = ['index', 'create', 'edit', 'view', 'submit_approval', 'approve', 'request_changes', 'send', 'resend', 'revise', 'withdraw',
    'doc_verify', 'doc_reject', 'request_corrections', 'compliance', 'final_approve', 'joining', 'templates', 'template', 'letter_edit', 'letter_docx', 'welcome', 'official_email', 'hires'];
if (in_array($a, $hrActions, true) && !$isStaff) {
    authorize('__never__');
}

/** The case, if the current user may see it: onboarding staff, or the case's own login (candidate / new employee). */
function onb_access(int $id): array
{
    global $isStaff;
    $o = onb_find($id) ?? not_found();
    if ($isStaff) return $o;
    if ((int) $o['login_id'] === (int) user()['id'] && in_array($o['status'], ONB_CANDIDATE_VISIBLE, true)) return $o;
    authorize('__never__');
}

function onb_url(array $o, array $p = []): string
{
    return url('onboarding', ['a' => 'view', 'id' => $o['id']] + $p);
}

/** Back to the page a POST came from (case page for HR, the portal step for the candidate). */
function onb_back(array $o, string $msgType = '', string $msg = '', ?string $to = null): never
{
    if ($msg !== '') flash($msgType, $msg);
    redirect($to ?? onb_url($o));
}

function onb_require_status(array $o, array $statuses): void
{
    if (!in_array($o['status'], $statuses, true)) {
        onb_back($o, 'warning', 'This action is not available while the case is <strong>' . e($o['status']) . '</strong>.', is_candidate() ? url('onboarding', ['a' => 'me']) : null);
    }
}

/** Signature pad + typed legal name + consent. Posts `signature`, `signed_name`, `sign_consent`. */
function onb_sign_fields(array $o, string $what, string $button, string $btnClass = 'btn-primary'): string
{
    $id = 'sig' . bin2hex(random_bytes(3));
    return '<div class="sig-block">'
        . '<div class="sig-pad" data-sig><div class="sig-head"><span class="small fw-semibold">Your signature</span>'
        . '<div class="btn-group btn-group-sm" role="group" aria-label="Signature mode"><button type="button" class="btn btn-light border active" data-sig-mode="draw"><i class="ri-pen-nib-line me-1"></i>Draw</button>'
        . '<button type="button" class="btn btn-light border" data-sig-mode="type"><i class="ri-text me-1"></i>Type</button></div></div>'
        . '<canvas aria-label="Signature box: draw your signature with the mouse or finger"></canvas>'
        . '<input type="text" class="form-control sig-type d-none" placeholder="Type your name to create a signature" maxlength="60" aria-label="Typed signature">'
        . '<div class="sig-foot"><span class="small text-muted" data-sig-hint>Sign inside the box</span><button type="button" class="btn btn-sm btn-link p-0" data-sig-clear>Clear</button></div>'
        . '<input type="hidden" name="signature" data-sig-out></div>'
        . '<div class="row g-2 mt-1"><div class="col-md-7"><label class="form-label small mb-1" for="' . $id . 'n">Full legal name <span class="text-danger">*</span></label>'
        . '<input class="form-control" id="' . $id . 'n" name="signed_name" required autocomplete="name" placeholder="' . e($o['full_name']) . '"></div>'
        . '<div class="col-md-5 d-flex align-items-end"><div class="small text-muted"><i class="ri-time-line"></i> ' . date('d M Y, h:i A') . ' · IP ' . e(client_ip()) . '</div></div></div>'
        . '<label class="form-check small mt-2"><input class="form-check-input" type="checkbox" name="sign_consent" value="1" required> <span class="form-check-label">I have read ' . e($what)
        . ' and I agree that my electronic signature above is legally binding, just like my handwritten signature (Information Technology Act, 2000).</span></label>'
        . '<button class="btn ' . $btnClass . ' mt-2" data-sig-submit>' . $button . '</button></div>';
}

/** Validates and stores a signature for one document of the case. */
function onb_sign(array $o, string $key, array $doc, string $html): void
{
    if (empty($_POST['sign_consent'])) throw new RuntimeException('Tick the box to confirm you agree to sign electronically.');
    $name = trim((string) ($_POST['signed_name'] ?? ''));
    if (!onb_name_matches($name, $o)) throw new RuntimeException('Type your full name exactly as it appears in the offer (' . $o['full_name'] . ').');
    $path = onb_store_signature((string) ($_POST['signature'] ?? ''), (int) $o['id'], $key);
    onb_record_signature($o, $key, $doc, $html, $name, $path);
    onb_log((int) $o['id'], $key === 'offer' ? 'offer_accepted' : 'document_signed', $doc['title'] . ' (v' . $doc['version'] . ')', 'candidate');
}

/* ═════════════════════════ Shared: offer letter, signed document, joining form ═════════════════════════ */

if ($a === 'letter') {
    $o = onb_access(int_input('id'));
    $draft = !$o['offer_html'];
    if ($draft && !$isStaff) not_found();
    $html = $o['offer_html'];
    $missing = [];
    if ($draft) {
        try { $html = onb_offer_html($o); $missing = hrd_missing_in($html); } catch (RuntimeException $ex) { $html = '<p class="text-muted">' . e($ex->getMessage()) . '</p>'; }
    }
    $sig = onb_signatures((int) $o['id'])['offer'] ?? null;
    $back = $isStaff ? onb_url($o) : url('onboarding', ['a' => 'me', 'step' => 'offer']);
    $editable = $draft && $canManage && in_array($o['status'], ['Draft', 'Changes Requested'], true);
    page_start('Offer letter · ' . $o['full_name'], $isStaff ? ['Onboarding' => url('onboarding'), $o['full_name'] => $back, 'Offer letter'] : ['My Onboarding' => $back, 'Offer letter'],
        ($editable ? '<a class="btn btn-light border" href="' . e(url('onboarding', ['a' => 'letter_edit', 'id' => $o['id']])) . '"><i class="ri-edit-2-line me-1"></i>Edit letter text</a>' : '')
        . ($isStaff ? '<a class="btn btn-light border" href="' . e(url('onboarding', ['a' => 'letter_docx', 'id' => $o['id']])) . '"><i class="ri-file-word-2-line me-1"></i>Download .docx</a>' : '')
        . '<button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>');
    if ($draft) echo '<div class="alert alert-warning d-print-none"><i class="ri-draft-line me-1"></i>Draft preview' . ($o['offer_draft'] ? ' of your edited text' : '') . '. The letter is fixed (and gets its date) when the offer is approved.</div>';
    if ($missing) echo '<div class="alert alert-danger d-print-none"><i class="ri-error-warning-line me-1"></i><strong>Missing details (highlighted below):</strong> ' . e(implode(', ', $missing)) . '. Complete the offer details' . ($editable ? ' or <a href="' . e(url('onboarding', ['a' => 'letter_edit', 'id' => $o['id']])) . '">edit the letter text</a>' : '') . ' before it can be approved or sent.</div>';
    $footer = $sig ? '<footer class="onb-sigline"><img src="' . e(file_url($sig['signature_file'])) . '" alt="Signature"><div><strong>Accepted by ' . e($sig['signed_name']) . '</strong><small>'
        . fdate($sig['signed_at'], 'd M Y, h:i A') . ' · IP ' . e($sig['ip']) . '</small></div></footer>' : '';
    echo '<div class="card payslip' . ($draft ? ' onb-draft' : '') . '"><div class="card-body p-4 p-md-5">' . onb_letter('Letter of offer', $html, $o, $footer) . '</div></div>';
    page_end();
    return;
}

/* ── Offer letter text: HR edits the candidate's own copy before approval (the reusable template is never changed) ── */
if ($a === 'letter_edit') {
    authorize('manage_onboarding');
    $o = onb_access(int_input('id'));
    if (!in_array($o['status'], ['Draft', 'Changes Requested'], true)) onb_back($o, 'warning', 'The letter is fixed once approved. Use "Revise offer" to change it.');
    if (is_post()) {
        if (input('do') === 'reset') {
            onb_save((int) $o['id'], ['offer_draft' => null]);
            onb_log((int) $o['id'], 'letter_reset', 'Letter text reset to the template');
            onb_back($o, 'success', 'Letter text reset to the template.', url('onboarding', ['a' => 'letter', 'id' => $o['id']]));
        }
        $html = doc_clean_html((string) ($_POST['content'] ?? ''));
        if (mb_strlen(trim(strip_tags($html))) < 50) onb_back($o, 'danger', 'The letter text is too short.', url('onboarding', ['a' => 'letter_edit', 'id' => $o['id']]));
        onb_save((int) $o['id'], ['offer_draft' => $html]);
        onb_log((int) $o['id'], 'letter_edited', mb_substr(trim((string) input('note')), 0, 200) ?: 'Letter text edited by HR');
        $miss = hrd_missing_in(onb_offer_html(onb_find((int) $o['id'])));
        onb_back($o, $miss ? 'warning' : 'success', 'Letter text saved.' . ($miss ? ' Still missing: ' . e(implode(', ', $miss)) . '.' : ''), url('onboarding', ['a' => 'letter', 'id' => $o['id']]));
    }
    try { $html = onb_offer_html($o, false); } catch (RuntimeException $ex) { onb_back($o, 'danger', e($ex->getMessage())); }
    page_start('Edit offer letter · ' . $o['full_name'], ['Onboarding' => url('onboarding'), $o['full_name'] => onb_url($o), 'Edit letter']);
    $miss = hrd_missing_in(onb_offer_html($o)); ?>
    <div class="grid grid-3">
      <form class="span-2 card" method="post" action="<?= e(url('onboarding', ['a' => 'letter_edit', 'id' => $o['id']])) ?>" data-doc-editor><?= csrf_field() ?>
        <div class="doc-toolbar" role="toolbar" aria-label="Formatting">
          <?php foreach ([['bold', 'ri-bold', 'Bold'], ['italic', 'ri-italic', 'Italic'], ['underline', 'ri-underline', 'Underline'], ['h2', 'ri-h-2', 'Heading'], ['h3', 'ri-h-3', 'Sub-heading'], ['p', 'ri-text', 'Paragraph'],
                         ['insertUnorderedList', 'ri-list-unordered', 'Bullets'], ['insertOrderedList', 'ri-list-ordered', 'Numbering'], ['table', 'ri-table-line', 'Insert table'], ['undo', 'ri-arrow-go-back-line', 'Undo'], ['redo', 'ri-arrow-go-forward-line', 'Redo']] as [$cmd, $ic, $t]): ?>
            <button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $t ?>" aria-label="<?= $t ?>"><i class="<?= $ic ?>"></i></button>
          <?php endforeach; ?><span class="ms-auto small text-muted" data-doc-state>Saved</span></div>
        <div class="doc-page"><div class="doc-edit policy-body" contenteditable="true" data-doc-body><?= $html ?></div></div>
        <textarea name="content" hidden></textarea>
        <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center"><input class="form-control form-control-sm" name="note" maxlength="200" placeholder="What changed? (audit note, optional)" style="max-width:360px">
          <button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save letter text</button></div>
      </form>
      <div class="vstack gap-3">
        <div class="card card-body small">
          <h6 class="fw-bold">This candidate's copy</h6>
          <p class="text-muted">Changes apply to <?= e($o['full_name']) ?>'s letter only. The template stays as it is. The letter is fixed when the offer is approved; after that it can only change through "Revise offer".</p>
          <?php if ($miss): ?><div class="alert alert-danger py-2 small mb-2"><strong>Missing:</strong> <?= e(implode(', ', $miss)) ?>. Replace each highlighted <mark class="ph-missing">[… missing]</mark> marker with the correct value or remove the sentence.</div><?php endif; ?>
          <p class="text-muted mb-2">The signature image of the chosen signatory is added automatically when the letter is fixed<?= $o['signatory_id'] ? '' : ' (no signatory chosen on the offer)' ?>.</p>
          <?php if ($o['offer_draft']): ?><?= post_button(url('onboarding', ['a' => 'letter_edit', 'id' => $o['id']]), 'Reset to template', 'btn btn-sm btn-outline-danger', 'Discard your edits and use the template text again?', ['do' => 'reset']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    page_end(['js/doceditor.js']);
    return;
}

/* ── Offer letter as Word (.docx): the fixed letter, or the current draft for HR review ── */
if ($a === 'letter_docx') {
    $o = onb_access(int_input('id'));
    if (!$isStaff) not_found();
    $html = $o['offer_html'] ?: onb_offer_html($o, false);
    onb_log((int) $o['id'], 'letter_downloaded', $o['offer_html'] ? 'Fixed letter (.docx)' : 'Draft (.docx)');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="Offer-' . preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($o['offer_ref'] ?: 'draft')) . '.docx"');
    header('Cache-Control: private, no-store');
    echo html_to_docx(doc_clean_html($html), '');
    exit;
}

/* ── Credentials: resend the welcome e-mail / a new password-setup link; official e-mail (never invented) ── */
if (($a === 'welcome' || $a === 'official_email') && is_post()) {
    authorize('manage_onboarding');
    $o = onb_access(int_input('id'));
    if (!$o['employee_id']) onb_back($o, 'warning', 'The employee record has not been created yet.');
    if ($a === 'official_email') {
        $mail = strtolower(trim((string) input('official_email')));
        $status = input('official_email_status') === 'Provisioned' ? 'Provisioned' : 'Pending';
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) onb_back($o, 'danger', 'Enter a valid official e-mail address.');
        if ($mail !== '' && val('SELECT id FROM hrm_employees WHERE official_email = ? AND id <> ?', [$mail, $o['employee_id']])) onb_back($o, 'danger', 'That official e-mail already belongs to another employee.');
        if ($mail === '' && $status === 'Provisioned') onb_back($o, 'danger', 'Enter the official e-mail before marking it as provisioned.');
        q('UPDATE hrm_employees SET official_email = ?, official_email_status = ? WHERE id = ?', [$mail ?: null, $mail ? $status : null, $o['employee_id']]);
        hrd_event(['employee_id' => $o['employee_id']], 'official_email', ($mail ? mask_email($mail) : 'cleared') . " · $status");
        onb_log((int) $o['id'], 'official_email', ($mail ?: 'cleared') . " · $status");
        onb_back($o, 'success', 'Official e-mail saved' . ($status === 'Provisioned' ? ' and marked as provisioned.' : ' as pending provisioning.'));
    }
    if (input('do') === 'link') {
        $lid = (int) val('SELECT id FROM it_login WHERE employee_id = ? ORDER BY id LIMIT 1', [$o['employee_id']]);
        if (!$lid) onb_back($o, 'danger', 'The employee has no HRMS login.');
        q('UPDATE it_login SET activation_hash = NULL WHERE id = ?', [$lid]); // the old link stops working
        hrd_event(['employee_id' => $o['employee_id']], 'activation', 'New password-setup link requested by HR');
        $o = onb_find((int) $o['id']);
    }
    try {
        $ok = onb_send_welcome($o, !empty($_POST['confirm_unverified']), input('do') === 'link');
    } catch (RuntimeException $ex) {
        onb_back($o, 'danger', e($ex->getMessage()));
    }
    onb_back($o, $ok ? 'success' : 'danger', $ok ? 'Welcome e-mail sent to ' . e(mask_email($o['email'])) . '.' : 'The mail server did not accept the e-mail. Check the mail settings and try again; no account was changed.');
}

if ($a === 'signed') {
    $sig = one('SELECT * FROM hrm_onboarding_signatures WHERE id = ?', [int_input('id')]) ?? not_found();
    $o = onb_access((int) $sig['onboarding_id']);
    $intact = hash_equals($sig['content_hash'], hash('sha256', $sig['content']));
    $back = $isStaff ? onb_url($o, ['tab' => 'esign']) : url('onboarding', ['a' => 'me', 'step' => 'esign']);
    page_start($sig['title'], $isStaff ? ['Onboarding' => url('onboarding'), $o['full_name'] => onb_url($o), 'E-signed document'] : ['My Onboarding' => $back, $sig['title']],
        '<button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>');
    $footer = '<footer class="onb-audit"><div class="onb-sigline"><img src="' . e(file_url($sig['signature_file'])) . '" alt="Signature"><div><strong>' . e($sig['signed_name']) . '</strong><small>Signed electronically</small></div></div>'
        . '<dl><div><dt>Signed at</dt><dd>' . fdate($sig['signed_at'], 'd M Y, h:i:s A') . ' IST</dd></div><div><dt>IP address</dt><dd>' . e($sig['ip']) . '</dd></div>'
        . '<div><dt>Device</dt><dd>' . e(device_label((string) $sig['user_agent'])) . '</dd></div><div><dt>Document version</dt><dd>' . e($sig['version']) . '</dd></div>'
        . '<div class="span-all"><dt>SHA-256 of the signed text</dt><dd class="font-monospace small text-break">' . e($sig['content_hash']) . ' '
        . ($intact ? '<span class="pill pill-success">Integrity verified</span>' : '<span class="pill pill-danger">Changed after signing</span>') . '</dd></div></dl></footer>';
    echo '<div class="card payslip"><div class="card-body p-4 p-md-5">' . onb_letter($sig['title'], $sig['content'], $o, $footer) . '</div></div>';
    page_end();
    return;
}

if ($a === 'print') {
    $o = onb_access(int_input('id'));
    $pr = $o['profile_data'];
    $p = $pr['personal'] ?? [];
    $b = $pr['bank'] ?? [];
    $s = $pr['statutory'] ?? [];
    $row = fn($l, $v) => '<div><dt>' . e($l) . '</dt><dd>' . ($v !== null && $v !== '' ? e($v) : '—') . '</dd></div>';
    page_start('Joining form · ' . $o['full_name'], $isStaff ? ['Onboarding' => url('onboarding'), $o['full_name'] => onb_url($o), 'Joining form'] : ['My Onboarding' => url('onboarding', ['a' => 'me']), 'Joining form'],
        '<button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>');
    $perm = !empty($p['permanent_same']) ? 'Same as current address' : trim(implode(', ', array_filter([$p['permanent_address'] ?? '', $p['permanent_city'] ?? '', $p['permanent_state'] ?? '', $p['permanent_pincode'] ?? ''])));
    $body = '<h3>Position</h3><dl class="detail-grid">' . $row('Designation', $o['des']) . $row('Department', $o['dept']) . $row('Date of joining', fdate($o['joining_date'])) . $row('Employment type', $o['employment_type']) . $row('Location', $o['work_location']) . $row('Annual CTC', money($o['ctc_annual'])) . '</dl>'
        . '<h3>Personal</h3><dl class="detail-grid">' . $row('Full name', trim(($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))) . $row('Father\'s / spouse\'s name', $p['father_name'] ?? '')
        . $row('Date of birth', fdate($p['dob'] ?? null)) . $row('Gender', $p['gender'] ?? '') . $row('Marital status', $p['marital_status'] ?? '') . $row('Blood group', $p['blood_group'] ?? '') . $row('Nationality', $p['nationality'] ?? '')
        . $row('Mobile', $p['phone'] ?? '') . $row('Personal e-mail', $p['personal_email'] ?? '') . $row('Current address', trim(implode(', ', array_filter([$p['current_address'] ?? '', $p['current_city'] ?? '', $p['current_state'] ?? '', $p['current_pincode'] ?? '']))))
        . $row('Permanent address', $perm) . $row('Emergency contact', trim(($p['emergency_name'] ?? '') . ' (' . ($p['emergency_relation'] ?? '') . ') ' . ($p['emergency_phone'] ?? ''))) . '</dl>'
        . '<h3>Education</h3><table class="table table-sm"><thead><tr><th>Qualification</th><th>Specialisation</th><th>Institution / board</th><th>Year</th><th>Score</th></tr></thead><tbody>';
    foreach ($pr['education'] ?? [] as $r) $body .= '<tr><td>' . e($r['qualification'] ?? '') . '</td><td>' . e($r['specialization'] ?? '') . '</td><td>' . e(trim(($r['institution'] ?? '') . (!empty($r['board']) ? ' · ' . $r['board'] : ''))) . '</td><td>' . e($r['year'] ?? '') . '</td><td>' . e($r['score'] ?? '') . '</td></tr>';
    $body .= '</tbody></table><h3>Employment history</h3>';
    if (($pr['experience_type'] ?? '') !== 'Experienced') $body .= '<p>Fresher.</p>';
    else {
        $body .= '<table class="table table-sm"><thead><tr><th>Employer</th><th>Designation</th><th>From</th><th>To</th><th>Last CTC</th><th>Reason for leaving</th></tr></thead><tbody>';
        foreach ($pr['employment'] ?? [] as $r) $body .= '<tr><td>' . e($r['employer'] ?? '') . '</td><td>' . e($r['designation'] ?? '') . '</td><td>' . fdate($r['from'] ?? null, 'M Y') . '</td><td>' . (!empty($r['to']) ? fdate($r['to'], 'M Y') : 'Present') . '</td><td>' . e($r['ctc'] ?? '') . '</td><td>' . e($r['reason'] ?? '') . '</td></tr>';
        $body .= '</tbody></table>';
    }
    $body .= '<h3>Bank &amp; statutory</h3><dl class="detail-grid">' . $row('Account holder', $b['account_holder'] ?? '') . $row('Bank / branch', trim(($b['bank_name'] ?? '') . (!empty($b['branch']) ? ', ' . $b['branch'] : '')))
        . $row('Account number', $b['account_number'] ?? '') . $row('IFSC', $b['ifsc'] ?? '') . $row('PAN', $s['pan'] ?? '') . $row('Aadhaar', !empty($s['aadhaar_last4']) ? 'XXXX XXXX ' . $s['aadhaar_last4'] : '')
        . $row('Previous PF member', $s['prev_pf_member'] ?? '') . $row('UAN', $s['uan'] ?? '') . $row('Previous PF account no.', $s['prev_pf_number'] ?? '') . $row('ESI IP number', $s['esi_number'] ?? '')
        . $row('International worker', $s['international_worker'] ?? 'No') . $row('Income-tax regime', $s['tax_regime'] ?? '')
        . $row('Nominee', trim(($s['nominee_name'] ?? '') . (!empty($s['nominee_relation']) ? ' (' . $s['nominee_relation'] . ')' : '') . (!empty($s['nominee_dob']) ? ', born ' . fdate($s['nominee_dob']) : ''))) . '</dl>'
        . '<h3>Declarations &amp; signatures</h3>';
    $body .= $o['declaration_at'] ? '<p>The candidate confirmed that the information is true and complete, authorised background verification and consented to the processing of their personal data on <strong>' . fdate($o['declaration_at'], 'd M Y, h:i A') . '</strong>.</p>' : '<p class="text-muted">Not yet submitted.</p>';
    $body .= '<table class="table table-sm"><thead><tr><th>Document</th><th>Version</th><th>Signed by</th><th>Signed at</th><th>Signature</th></tr></thead><tbody>';
    foreach (onb_signatures((int) $o['id']) as $sg) $body .= '<tr><td>' . e($sg['title']) . '</td><td>' . e($sg['version']) . '</td><td>' . e($sg['signed_name']) . '</td><td>' . fdate($sg['signed_at'], 'd M Y, h:i A') . '</td><td><img class="onb-sig-thumb" src="' . e(file_url($sg['signature_file'])) . '" alt=""></td></tr>';
    $body .= '</tbody></table>';
    echo '<div class="card payslip"><div class="card-body p-4 p-md-5">' . onb_letter('Employee joining form', $body, $o) . '</div></div>';
    page_end();
    return;
}

/* ═════════════════════════ Candidate portal ═════════════════════════ */

if (in_array($a, ['me', 'accept', 'decline', 'save', 'upload', 'sign', 'submit'], true)) {
    $o = onb_for_login((int) user()['id'], true);
    if ($a !== 'me' && !$o) authorize('__never__');
    $me = fn(string $step = '') => url('onboarding', ['a' => 'me', 'step' => $step ?: null]);
    $editable = $o && in_array($o['status'], ONB_CANDIDATE_EDIT, true);

    /* ── Accept / decline the offer ── */
    if ($a === 'accept' && is_post()) {
        onb_require_status($o, ['Offer Sent']);
        try {
            onb_sign($o, 'offer', onb_sign_docs($o)['offer'], (string) $o['offer_html']);
        } catch (RuntimeException $ex) {
            onb_back($o, 'danger', e($ex->getMessage()), $me('offer'));
        }
        // Start the form with what we already know about them.
        $parts = preg_split('/\s+/', trim($o['full_name']));
        $profile = $o['profile_data'];
        $profile['personal'] ??= ['first_name' => $parts[0], 'last_name' => count($parts) > 1 ? end($parts) : '', 'middle_name' => count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : '',
            'phone' => $o['phone'], 'personal_email' => $o['email'], 'nationality' => 'Indian', 'permanent_same' => '1'];
        onb_save((int) $o['id'], ['status' => 'Accepted', 'accepted_at' => date('Y-m-d H:i:s'), 'response_ip' => client_ip(), 'profile' => $profile]);
        onb_candidate_stage($o, 'Offered', 'Offer accepted by the candidate (' . $o['offer_ref'] . ')');
        onb_notify_hr($o, 'offer accepted', "{$o['full_name']} accepted the offer for {$o['des']} (joining " . fdate($o['joining_date']) . ") and is now completing the onboarding form.");
        onb_back($o, 'success', '<strong>Welcome aboard!</strong> Your acceptance is signed and recorded. Next, fill in your personal information.', $me('personal'));
    }
    if ($a === 'decline' && is_post()) {
        onb_require_status($o, ['Offer Sent']);
        $reason = mb_substr(trim((string) input('reason')), 0, 500);
        if ($reason === '') onb_back($o, 'danger', 'Please tell us briefly why you are declining.', $me('offer'));
        onb_save((int) $o['id'], ['status' => 'Declined', 'declined_at' => date('Y-m-d H:i:s'), 'decline_reason' => $reason, 'response_ip' => client_ip()]);
        onb_log((int) $o['id'], 'offer_declined', $reason, 'candidate');
        onb_candidate_stage($o, 'Rejected', 'Offer declined by the candidate: ' . $reason);
        onb_notify_hr($o, 'offer declined', "{$o['full_name']} declined the offer for {$o['des']}.\n\nReason: $reason");
        onb_back($o, 'info', 'Thank you for letting us know. We have informed HR.', $me('offer'));
    }

    /* ── Save a form section ── */
    if ($a === 'save' && is_post()) {
        if (!$editable) onb_back($o, 'warning', 'Your form is with HR and cannot be changed right now.', $me());
        $step = (string) input('step');
        $pr = $o['profile_data'];
        $txt = fn($v, $max = 190) => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $v)), 0, $max);
        if ($step === 'personal') {
            $p = [];
            foreach (['first_name', 'middle_name', 'last_name', 'father_name', 'gender', 'marital_status', 'blood_group', 'nationality', 'personal_email', 'phone', 'alt_phone',
                'current_city', 'current_state', 'current_pincode', 'permanent_city', 'permanent_state', 'permanent_pincode', 'emergency_name', 'emergency_relation', 'emergency_phone'] as $k) {
                $p[$k] = $txt($_POST[$k] ?? '', 150);
            }
            foreach (['current_address', 'permanent_address'] as $k) $p[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 500);
            $p['dob'] = valid_date(input('dob')) ?? '';
            $p['permanent_same'] = !empty($_POST['permanent_same']) ? '1' : '';
            foreach (['gender' => ONB_GENDERS, 'marital_status' => ['Single', 'Married', 'Other'], 'blood_group' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
                'emergency_relation' => ONB_RELATIONS, 'nationality' => ['Indian', 'Other']] as $k => $allowed) {
                if (!in_array($p[$k], $allowed, true)) $p[$k] = '';
            }
            $pr['personal'] = $p;
            $errors = onb_check_personal($p);
        } elseif ($step === 'education') {
            $pr['experience_type'] = in_array(input('experience_type'), ['Fresher', 'Experienced'], true) ? input('experience_type') : '';
            $pr['education'] = [];
            foreach ((array) ($_POST['edu'] ?? []) as $r) {
                if (!is_array($r) || !array_filter($r, fn($v) => trim((string) $v) !== '')) continue;
                $pr['education'][] = ['qualification' => in_array($r['qualification'] ?? '', ONB_QUALIFICATIONS, true) ? $r['qualification'] : '', 'specialization' => $txt($r['specialization'] ?? ''),
                    'institution' => $txt($r['institution'] ?? ''), 'board' => $txt($r['board'] ?? ''), 'year' => preg_replace('/\D+/', '', (string) ($r['year'] ?? '')), 'score' => $txt($r['score'] ?? '', 30)];
            }
            $pr['employment'] = [];
            if ($pr['experience_type'] === 'Experienced') {
                foreach ((array) ($_POST['job'] ?? []) as $r) {
                    if (!is_array($r) || !array_filter($r, fn($v) => trim((string) $v) !== '')) continue;
                    $pr['employment'][] = ['employer' => $txt($r['employer'] ?? ''), 'designation' => $txt($r['designation'] ?? ''), 'from' => valid_date($r['from'] ?? null) ?? '',
                        'to' => valid_date($r['to'] ?? null) ?? '', 'ctc' => $txt($r['ctc'] ?? '', 40), 'reason' => $txt($r['reason'] ?? '', 250)];
                }
            }
            $errors = onb_check_education($pr);
        } elseif ($step === 'bank') {
            $acc = preg_replace('/\s+/', '', (string) input('account_number'));
            $pr['bank'] = ['account_holder' => $txt(input('account_holder'), 150), 'bank_name' => $txt(input('bank_name'), 120), 'branch' => $txt(input('branch'), 120),
                'account_number' => $acc, 'ifsc' => strtoupper(preg_replace('/\s+/', '', (string) input('ifsc'))), 'account_type' => in_array(input('account_type'), ONB_ACCOUNT_TYPES, true) ? input('account_type') : 'Savings'];
            $s = $pr['statutory'] ?? [];
            $aadhaar = onb_digits(input('aadhaar'));
            $errors = [];
            if ($acc !== preg_replace('/\s+/', '', (string) input('account_number2'))) $errors[] = 'The two account numbers do not match.';
            if ($aadhaar !== '') {
                if (onb_valid_aadhaar($aadhaar)) $s['aadhaar_last4'] = substr($aadhaar, -4);
                else $errors[] = 'That Aadhaar number is not valid. Check the 12 digits.';
            }
            $s['pan'] = strtoupper(preg_replace('/\s+/', '', (string) input('pan')));
            $s['uan'] = onb_digits(input('uan'));
            $s['prev_pf_number'] = $txt(input('prev_pf_number'), 40);
            $s['esi_number'] = onb_digits(input('esi_number'));
            foreach (['prev_pf_member' => ['Yes', 'No'], 'prev_eps_member' => ['Yes', 'No'], 'international_worker' => ['No', 'Yes'], 'tax_regime' => ['New', 'Old']] as $k => $allowed) {
                $s[$k] = in_array(input($k), $allowed, true) ? input($k) : '';
            }
            $s['nominee_name'] = $txt(input('nominee_name'), 150);
            $s['nominee_relation'] = in_array(input('nominee_relation'), ONB_RELATIONS, true) ? input('nominee_relation') : '';
            $s['nominee_dob'] = valid_date(input('nominee_dob')) ?? '';
            $pr['statutory'] = $s;
            $errors = array_merge($errors, onb_check_bank($pr));
        } else {
            not_found();
        }
        onb_save((int) $o['id'], ['profile' => $pr]);
        onb_log((int) $o['id'], 'step_saved', ONB_STEPS[$step][0], 'candidate');
        if ($errors) {
            $_SESSION['_old'] = $_POST;
            onb_back($o, 'warning', 'Saved, but please fix:<br>' . implode('<br>', array_map('e', array_unique($errors))), $me($step));
        }
        $next = array_keys(ONB_STEPS)[array_search($step, array_keys(ONB_STEPS), true) + 1];
        onb_back($o, 'success', e(ONB_STEPS[$step][0]) . ' saved.', $me($next));
    }

    /* ── Upload a document ── */
    if ($a === 'upload' && is_post()) {
        if (!$editable) onb_back($o, 'warning', 'Your form is with HR and cannot be changed right now.', $me('documents'));
        $types = onb_document_types();
        $type = (string) input('doc_type');
        if (!isset($types[$type])) not_found();
        $current = onb_documents((int) $o['id'])[$type] ?? null;
        if ($current && $current['status'] === 'Verified') onb_back($o, 'info', 'HR has already verified this document.', $me('documents'));
        try {
            $path = store_upload('file', 'onboarding', 10);
            if (!$path) throw new RuntimeException('Choose a file to upload.');
        } catch (RuntimeException $ex) {
            onb_back($o, 'danger', e($types[$type]['label']) . ': ' . e($ex->getMessage()), $me('documents'));
        }
        if ($type === 'photo' && !preg_match('/\.(jpe?g|png)$/i', $path)) {
            @unlink(STORAGE_DIR . '/uploads/' . $path);
            onb_back($o, 'danger', 'The photograph must be a JPG or PNG image.', $me('documents'));
        }
        if ($current) q("UPDATE hrm_onboarding_documents SET status = 'Replaced' WHERE id = ?", [$current['id']]);
        insert('hrm_onboarding_documents', ['onboarding_id' => $o['id'], 'doc_type' => $type, 'title' => mb_substr($types[$type]['label'], 0, 150), 'file' => $path,
            'original_name' => mb_substr(basename((string) ($_FILES['file']['name'] ?? '')), 0, 190), 'status' => 'Pending']);
        onb_log((int) $o['id'], 'document_uploaded', $types[$type]['label'] . ($current ? ' (replaced)' : ''), 'candidate');
        onb_back($o, 'success', e($types[$type]['label']) . ' uploaded.', $me('documents'));
    }

    /* ── E-sign a document ── */
    if ($a === 'sign' && is_post()) {
        if (!$editable) onb_back($o, 'warning', 'Your form is with HR and cannot be changed right now.', $me('esign'));
        $key = (string) input('doc');
        $docs = onb_unsigned($o);
        if (!isset($docs[$key]) || $key === 'offer') onb_back($o, 'info', 'This document is already signed.', $me('esign'));
        try {
            onb_sign($o, $key, $docs[$key], onb_sign_doc_html($docs[$key], $o));
        } catch (RuntimeException $ex) {
            onb_back($o, 'danger', e($ex->getMessage()), $me('esign') . '#doc-' . $key);
        }
        onb_back($o, 'success', e($docs[$key]['title']) . ' signed.', $me('esign'));
    }

    /* ── Final submission ── */
    if ($a === 'submit' && is_post()) {
        onb_require_status($o, ONB_CANDIDATE_EDIT);
        $open = array_filter(onb_step_status($o), fn($s, $k) => !$s['done'] && $k !== 'submit', ARRAY_FILTER_USE_BOTH);
        if ($open) onb_back($o, 'danger', 'Please complete every section first: ' . e(implode(', ', array_column($open, 'label'))) . '.', $me('submit'));
        if (empty($_POST['decl_true']) || empty($_POST['decl_bgv']) || empty($_POST['decl_data'])) onb_back($o, 'danger', 'Please confirm all three declarations.', $me('submit'));
        $again = $o['status'] === 'Correction Requested';
        onb_save((int) $o['id'], ['status' => 'Submitted', 'form_submitted_at' => date('Y-m-d H:i:s'), 'declaration_at' => date('Y-m-d H:i:s')]);
        onb_log((int) $o['id'], 'form_submitted', $again ? 'Resubmitted after corrections' : null, 'candidate');
        onb_notify_hr($o, $again ? 'corrections submitted' : 'form submitted', "{$o['full_name']} " . ($again ? 'made the requested corrections and resubmitted' : 'submitted') . " the onboarding form. Please verify the documents and complete the compliance check.");
        onb_mail_candidate($o, 'We received your onboarding form', "Thank you. Your onboarding form and documents are with our HR team for verification. We'll e-mail you if anything needs a correction, and again when your onboarding is approved.");
        onb_back($o, 'success', '<strong>Submitted!</strong> HR will now verify your documents. We\'ll e-mail you with any update.', $me());
    }

    /* ── The portal page ── */
    if (!$o) {
        page_start('My Onboarding', ['My Onboarding']);
        echo '<div class="card">';
        empty_state('ri-user-received-2-line', 'No onboarding yet', 'When you receive an offer, you\'ll complete your joining formalities here.');
        echo '</div>';
        page_end();
        return;
    }
    $steps = $o['accepted_at'] ? onb_step_status($o) : null;
    $step = (string) ($_GET['step'] ?? '');
    $open = $o['status'] === 'Offer Sent' || in_array($o['status'], ['Declined', 'Expired'], true);
    if ($open || !isset(ONB_STEPS[$step])) {
        // Default: the first open step while filling the form, otherwise the summary.
        $step = $open ? 'offer' : ($editable ? (array_keys(array_filter($steps, fn($s) => !$s['done']))[0] ?? 'submit') : 'submit');
    }
    $first = explode(' ', $o['full_name'])[0];
    $pct = onb_percent($o);
    page_start('My Onboarding', ['My Onboarding'], $o['accepted_at'] ? '<a class="btn btn-light border" href="' . e(url('onboarding', ['a' => 'print', 'id' => $o['id']])) . '"><i class="ri-printer-line me-1"></i>Joining form</a>' : '');
    $statusText = [
        'Offer Sent' => 'Your offer is ready. Read it carefully and accept it by ' . fdate($o['offer_expires'], 'd M Y') . '.',
        'Accepted' => 'Complete each section below and submit your form for HR verification.',
        'Correction Requested' => 'HR asked for a few corrections. Fix the items marked below and submit again.',
        'Submitted' => 'Your form is with HR for verification. We\'ll e-mail you with any update.',
        'Ready to Join' => 'Your onboarding is approved. See you on ' . fdate($o['joining_date'], 'd M Y') . '!',
        'Activated' => 'Your onboarding is approved and your employee profile is active. See you on ' . fdate($o['joining_date'], 'd M Y') . '!',
        'Joined' => 'Welcome to the team! Your onboarding is complete.',
        'Declined' => 'You declined this offer on ' . fdate($o['declined_at']) . '.',
        'Expired' => 'This offer expired on ' . fdate($o['offer_expires']) . '. Please contact HR if you are still interested.',
    ][$o['status']] ?? '';
    ?>
    <div class="hero mb-4"><div class="hero-inner">
      <div><div class="opacity-75 small"><?= e(setting('company_name')) ?> · <?= e($o['offer_ref']) ?></div>
        <h2><?= $o['status'] === 'Offer Sent' ? 'Congratulations, ' . e($first) . '! 🎉' : 'Hi ' . e($first) . ' 👋' ?></h2>
        <p class="mb-0"><?= e($statusText) ?></p></div>
      <div class="clock-box" style="min-width:250px">
        <div class="small opacity-75"><?= e($o['des'] ?: 'Your role') ?><?= $o['dept'] ? ' · ' . e($o['dept']) : '' ?></div>
        <div class="fw-bold fs-5">Joining <?= fdate($o['joining_date'], 'd M Y') ?></div>
        <?php if ($o['accepted_at']): ?><div class="d-flex justify-content-between small mt-2"><span class="opacity-75">Onboarding</span><strong><?= $pct ?>%</strong></div>
          <div class="progress mt-1" style="height:8px;background:rgba(255,255,255,.25)"><div class="progress-bar bg-white" style="width:<?= $pct ?>%"></div></div><?php endif; ?>
      </div>
    </div></div>
    <?php if ($o['status'] === 'Correction Requested' && $o['return_note']): ?>
      <div class="alert alert-warning d-flex gap-2"><i class="ri-error-warning-line fs-5"></i><div><strong>Corrections requested by HR</strong><div class="small"><?= nl2br(e($o['return_note'])) ?></div></div></div>
    <?php endif; ?>

    <div class="onb-portal">
      <nav class="onb-steps-nav card" aria-label="Onboarding steps">
        <?php foreach (ONB_STEPS as $k => [$label, $icon]):
            $locked = !$o['accepted_at'] && $k !== 'offer';
            $done = $k === 'offer' ? (bool) $o['accepted_at'] : ($steps[$k]['done'] ?? false);
            $attn = $steps && !$done && $k !== 'submit' && $o['status'] === 'Correction Requested' && $steps[$k]['issues']; ?>
          <a class="<?= $k === $step ? 'active' : '' ?> <?= $done ? 'done' : '' ?> <?= $locked ? 'locked' : '' ?>" href="<?= $locked ? '#' : e($me($k)) ?>" <?= $locked ? 'aria-disabled="true" tabindex="-1"' : '' ?> <?= $k === $step ? 'aria-current="step"' : '' ?>>
            <span class="onb-dot"><i class="<?= $done ? 'ri-check-line' : ($locked ? 'ri-lock-line' : $icon) ?>"></i></span>
            <span class="grow"><?= e($label) ?></span><?= $attn ? '<i class="ri-error-warning-fill text-warning" title="Needs attention"></i>' : '' ?>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="onb-main vstack gap-3">
      <?php
      $ro = !$editable;
      $roNote = $ro && $step !== 'offer' && $o['accepted_at'] ? '<div class="alert alert-light border small mb-0"><i class="ri-lock-line me-1"></i>Your form is with HR, so it can\'t be changed now. If something is wrong, write to ' . e(setting('company_email')) . '.</div>' : '';
      if ($step === 'offer'):
          $sig = onb_signatures((int) $o['id'])['offer'] ?? null; ?>
        <div class="card payslip m-0"><div class="card-body p-4 p-md-5"><?= onb_letter('Letter of offer', (string) $o['offer_html'], $o) ?></div></div>
        <?php if ($o['status'] === 'Offer Sent'): ?>
          <form method="post" action="<?= e(url('onboarding', ['a' => 'accept'])) ?>" class="card form-block" data-sign-form>
            <?= csrf_field() ?>
            <div class="form-block-head"><span class="fb-icon"><i class="ri-quill-pen-line"></i></span><div><h6>Accept your offer</h6><small>Sign below to accept. Valid until <?= fdate($o['offer_expires'], 'd M Y') ?>.</small></div></div>
            <div class="form-block-body"><?= onb_sign_fields($o, 'this offer letter', '<i class="ri-check-double-line me-1"></i>Accept &amp; sign offer', 'btn-success btn-lg') ?></div>
          </form>
          <details class="card card-body onb-decline">
            <summary class="text-muted small">Not accepting this offer?</summary>
            <form method="post" action="<?= e(url('onboarding', ['a' => 'decline'])) ?>" class="mt-3 vstack gap-2"><?= csrf_field() ?>
              <label class="form-label small" for="dreason">Please tell us why (this helps us improve)</label>
              <textarea class="form-control" id="dreason" name="reason" rows="3" required maxlength="500"></textarea>
              <div><button class="btn btn-outline-danger" data-confirm="Decline this offer? This cannot be undone online.">Decline offer</button></div>
            </form>
          </details>
        <?php elseif ($sig): ?>
          <div class="card card-body d-flex flex-row flex-wrap gap-3 align-items-center"><i class="ri-checkbox-circle-fill text-success fs-3"></i>
            <div class="grow"><strong>You accepted this offer</strong><div class="small text-muted">Signed by <?= e($sig['signed_name']) ?> on <?= fdate($sig['signed_at'], 'd M Y, h:i A') ?></div></div>
            <a class="btn btn-light border btn-sm" href="<?= e(url('onboarding', ['a' => 'letter', 'id' => $o['id']])) ?>"><i class="ri-download-2-line me-1"></i>Signed offer letter</a></div>
        <?php endif; ?>

      <?php elseif ($step === 'personal'):
          $p = $o['profile_data']['personal'] ?? [];
          $v = fn($k, $d = '') => old($k, $p[$k] ?? $d);
          $states = array_combine(INDIAN_STATES, INDIAN_STATES); ?>
        <form method="post" action="<?= e(url('onboarding', ['a' => 'save'])) ?>" class="vstack gap-3" novalidate><?= csrf_field() ?><input type="hidden" name="step" value="personal">
          <?= $roNote ?>
          <fieldset <?= $ro ? 'disabled' : '' ?> class="vstack gap-3">
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-user-3-line"></i></span><div><h6>About you</h6><small>As on your PAN / Aadhaar. Used for your employment records and PF.</small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?= form_field('first_name', ['label' => 'First name', 'required' => true, 'col' => 'col-md-4', 'maxlength' => 80], $v('first_name')) ?>
              <?= form_field('middle_name', ['label' => 'Middle name', 'col' => 'col-md-4', 'maxlength' => 80], $v('middle_name')) ?>
              <?= form_field('last_name', ['label' => 'Last name', 'required' => true, 'col' => 'col-md-4', 'maxlength' => 80], $v('last_name')) ?>
              <?= form_field('father_name', ['label' => 'Father\'s / spouse\'s name', 'required' => true, 'col' => 'col-md-4', 'help' => 'Needed for your PF (EPFO) records.'], $v('father_name')) ?>
              <?= form_field('dob', ['label' => 'Date of birth', 'type' => 'date', 'required' => true, 'col' => 'col-md-4', 'max' => date('Y-m-d', strtotime('-18 years'))], $v('dob')) ?>
              <?= form_field('gender', ['label' => 'Gender', 'type' => 'select', 'options' => ONB_GENDERS, 'required' => true, 'col' => 'col-md-4'], $v('gender')) ?>
              <?= form_field('marital_status', ['label' => 'Marital status', 'type' => 'select', 'options' => ['Single', 'Married', 'Other'], 'col' => 'col-md-4'], $v('marital_status')) ?>
              <?= form_field('blood_group', ['label' => 'Blood group', 'type' => 'select', 'options' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 'col' => 'col-md-4'], $v('blood_group')) ?>
              <?= form_field('nationality', ['label' => 'Nationality', 'type' => 'select', 'options' => ['Indian', 'Other'], 'required' => true, 'col' => 'col-md-4'], $v('nationality', 'Indian')) ?>
              <?= form_field('phone', ['label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'col' => 'col-md-4', 'autocomplete' => 'tel'], $v('phone', $o['phone'])) ?>
              <?= form_field('alt_phone', ['label' => 'Alternate number', 'type' => 'tel', 'col' => 'col-md-4'], $v('alt_phone')) ?>
              <?= form_field('personal_email', ['label' => 'Personal e-mail', 'type' => 'email', 'col' => 'col-md-4'], $v('personal_email', $o['email'])) ?>
            </div></div></section>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-map-pin-line"></i></span><div><h6>Address</h6></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?= form_field('current_address', ['label' => 'Current address', 'type' => 'textarea', 'rows' => 2, 'required' => true, 'col' => 'col-12', 'placeholder' => 'House / flat, street, area'], $v('current_address')) ?>
              <?= form_field('current_city', ['label' => 'City', 'required' => true, 'col' => 'col-md-4'], $v('current_city')) ?>
              <?= form_field('current_state', ['label' => 'State', 'type' => 'select', 'options' => $states + ['Outside India' => 'Outside India'], 'required' => true, 'col' => 'col-md-4'], $v('current_state')) ?>
              <?= form_field('current_pincode', ['label' => 'PIN code', 'required' => true, 'col' => 'col-md-4', 'maxlength' => 10, 'pattern' => '[0-9]{6}'], $v('current_pincode')) ?>
              <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="permanent_same" value="1" data-toggle-target="#permAddr" <?= $v('permanent_same', '1') ? 'checked' : '' ?>> <span class="form-check-label">Permanent address is the same as current address</span></label></div>
              <div class="col-12" id="permAddr"><div class="row g-3">
                <?= form_field('permanent_address', ['label' => 'Permanent address', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-12'], $v('permanent_address')) ?>
                <?= form_field('permanent_city', ['label' => 'City', 'col' => 'col-md-4'], $v('permanent_city')) ?>
                <?= form_field('permanent_state', ['label' => 'State', 'type' => 'select', 'options' => $states + ['Outside India' => 'Outside India'], 'col' => 'col-md-4'], $v('permanent_state')) ?>
                <?= form_field('permanent_pincode', ['label' => 'PIN code', 'col' => 'col-md-4', 'maxlength' => 10], $v('permanent_pincode')) ?>
              </div></div>
            </div></div></section>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-first-aid-kit-line"></i></span><div><h6>Emergency contact</h6><small>Someone we can call if something happens to you at work.</small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?= form_field('emergency_name', ['label' => 'Name', 'required' => true, 'col' => 'col-md-4'], $v('emergency_name')) ?>
              <?= form_field('emergency_relation', ['label' => 'Relation', 'type' => 'select', 'options' => ONB_RELATIONS, 'required' => true, 'col' => 'col-md-4'], $v('emergency_relation')) ?>
              <?= form_field('emergency_phone', ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'col' => 'col-md-4'], $v('emergency_phone')) ?>
            </div></div></section>
          </fieldset>
          <?php if (!$ro): ?><div class="form-actions sticky-actions"><a class="btn btn-light border" href="<?= e($me('offer')) ?>">Back</a><button class="btn btn-primary ms-auto">Save &amp; continue <i class="ri-arrow-right-line"></i></button></div><?php endif; ?>
        </form>

      <?php elseif ($step === 'education'):
          $pr = $o['profile_data'];
          $old = $_SESSION['_old'] ?? null;
          $edu = $old ? array_values((array) ($old['edu'] ?? [])) : ($pr['education'] ?? []);
          $jobs = $old ? array_values((array) ($old['job'] ?? [])) : ($pr['employment'] ?? []);
          $expType = $old['experience_type'] ?? ($pr['experience_type'] ?? '');
          if (!$edu) $edu = [[]];
          if (!$jobs) $jobs = [[]];
          $eduRow = function ($i, $r) {
              $sel = '<option value="">— Select —</option>' . implode('', array_map(fn($q) => '<option' . (($r['qualification'] ?? '') === $q ? ' selected' : '') . '>' . e($q) . '</option>', ONB_QUALIFICATIONS));
              return '<div class="onb-row" data-row><div class="row g-2">'
                  . '<div class="col-md-3"><label class="form-label small">Qualification *</label><select class="form-select" name="edu[' . $i . '][qualification]">' . $sel . '</select></div>'
                  . '<div class="col-md-3"><label class="form-label small">Specialisation</label><input class="form-control" name="edu[' . $i . '][specialization]" value="' . e($r['specialization'] ?? '') . '" placeholder="e.g. Computer Science"></div>'
                  . '<div class="col-md-3"><label class="form-label small">Institution *</label><input class="form-control" name="edu[' . $i . '][institution]" value="' . e($r['institution'] ?? '') . '"></div>'
                  . '<div class="col-md-3"><label class="form-label small">Board / university</label><input class="form-control" name="edu[' . $i . '][board]" value="' . e($r['board'] ?? '') . '"></div>'
                  . '<div class="col-6 col-md-2"><label class="form-label small">Passing year *</label><input class="form-control" name="edu[' . $i . '][year]" inputmode="numeric" maxlength="4" value="' . e($r['year'] ?? '') . '"></div>'
                  . '<div class="col-6 col-md-2"><label class="form-label small">Score</label><input class="form-control" name="edu[' . $i . '][score]" value="' . e($r['score'] ?? '') . '" placeholder="78% / 8.2 CGPA"></div>'
                  . '<div class="col-md-8 d-flex align-items-end justify-content-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-row><i class="ri-delete-bin-6-line me-1"></i>Remove</button></div>'
                  . '</div></div>';
          };
          $jobRow = function ($i, $r) {
              return '<div class="onb-row" data-row><div class="row g-2">'
                  . '<div class="col-md-4"><label class="form-label small">Employer *</label><input class="form-control" name="job[' . $i . '][employer]" value="' . e($r['employer'] ?? '') . '"></div>'
                  . '<div class="col-md-4"><label class="form-label small">Designation *</label><input class="form-control" name="job[' . $i . '][designation]" value="' . e($r['designation'] ?? '') . '"></div>'
                  . '<div class="col-6 col-md-2"><label class="form-label small">From *</label><input class="form-control" type="date" name="job[' . $i . '][from]" value="' . e($r['from'] ?? '') . '"></div>'
                  . '<div class="col-6 col-md-2"><label class="form-label small">To</label><input class="form-control" type="date" name="job[' . $i . '][to]" value="' . e($r['to'] ?? '') . '" title="Leave empty if you still work there"></div>'
                  . '<div class="col-md-3"><label class="form-label small">Last CTC</label><input class="form-control" name="job[' . $i . '][ctc]" value="' . e($r['ctc'] ?? '') . '" placeholder="e.g. 6 LPA"></div>'
                  . '<div class="col-md-6"><label class="form-label small">Reason for leaving</label><input class="form-control" name="job[' . $i . '][reason]" value="' . e($r['reason'] ?? '') . '"></div>'
                  . '<div class="col-md-3 d-flex align-items-end justify-content-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-row><i class="ri-delete-bin-6-line me-1"></i>Remove</button></div>'
                  . '</div></div>';
          }; ?>
        <form method="post" action="<?= e(url('onboarding', ['a' => 'save'])) ?>" class="vstack gap-3" novalidate><?= csrf_field() ?><input type="hidden" name="step" value="education">
          <?= $roNote ?>
          <fieldset <?= $ro ? 'disabled' : '' ?> class="vstack gap-3">
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-graduation-cap-line"></i></span><div><h6>Education</h6><small>Highest qualification first.</small></div></div>
            <div class="form-block-body" data-rows>
              <?php foreach ($edu as $i => $r) echo $eduRow($i, $r); ?>
              <template data-row-template><?= $eduRow('__i__', []) ?></template>
              <button type="button" class="btn btn-sm btn-light border mt-2" data-add-row><i class="ri-add-line me-1"></i>Add qualification</button>
            </div></section>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-briefcase-4-line"></i></span><div><h6>Employment history</h6><small>Most recent first. We may verify this with your previous employers.</small></div></div>
            <div class="form-block-body">
              <div class="d-flex gap-4 mb-3" role="radiogroup" aria-label="Experience">
                <?php foreach (['Fresher' => 'I am a fresher', 'Experienced' => 'I have work experience'] as $val => $lbl): ?>
                  <label class="form-check"><input class="form-check-input" type="radio" name="experience_type" value="<?= $val ?>" data-exp-toggle <?= $expType === $val ? 'checked' : '' ?>> <span class="form-check-label"><?= $lbl ?></span></label>
                <?php endforeach; ?>
              </div>
              <div data-rows data-exp-block <?= $expType === 'Experienced' ? '' : 'hidden' ?>>
                <?php foreach ($jobs as $i => $r) echo $jobRow($i, $r); ?>
                <template data-row-template><?= $jobRow('__i__', []) ?></template>
                <button type="button" class="btn btn-sm btn-light border mt-2" data-add-row><i class="ri-add-line me-1"></i>Add employer</button>
              </div>
            </div></section>
          </fieldset>
          <?php if (!$ro): ?><div class="form-actions sticky-actions"><a class="btn btn-light border" href="<?= e($me('personal')) ?>">Back</a><button class="btn btn-primary ms-auto">Save &amp; continue <i class="ri-arrow-right-line"></i></button></div><?php endif; ?>
        </form>

      <?php elseif ($step === 'bank'):
          $b = $o['profile_data']['bank'] ?? [];
          $s = $o['profile_data']['statutory'] ?? [];
          $vb = fn($k, $d = '') => old($k, $b[$k] ?? $d);
          $vs = fn($k, $d = '') => old($k, $s[$k] ?? $d); ?>
        <form method="post" action="<?= e(url('onboarding', ['a' => 'save'])) ?>" class="vstack gap-3" novalidate autocomplete="off"><?= csrf_field() ?><input type="hidden" name="step" value="bank">
          <?= $roNote ?>
          <fieldset <?= $ro ? 'disabled' : '' ?> class="vstack gap-3">
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-bank-line"></i></span><div><h6>Salary account</h6><small>Your salary is paid to this account. It must be in your name.</small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?= form_field('account_holder', ['label' => 'Account holder name', 'required' => true, 'col' => 'col-md-6'], $vb('account_holder', $o['full_name'])) ?>
              <?= form_field('account_type', ['label' => 'Account type', 'type' => 'select', 'options' => ONB_ACCOUNT_TYPES, 'col' => 'col-md-6'], $vb('account_type', 'Savings')) ?>
              <?= form_field('account_number', ['label' => 'Account number', 'required' => true, 'col' => 'col-md-6', 'maxlength' => 18, 'pattern' => '[0-9]{9,18}'], $vb('account_number')) ?>
              <?= form_field('account_number2', ['label' => 'Re-enter account number', 'required' => true, 'col' => 'col-md-6', 'maxlength' => 18], old('account_number2', $b['account_number'] ?? '')) ?>
              <div class="col-md-4"><label class="form-label" for="f_ifsc">IFSC <span class="text-danger">*</span></label><input class="form-control text-uppercase" id="f_ifsc" name="ifsc" maxlength="11" value="<?= e($vb('ifsc')) ?>" placeholder="HDFC0001234" data-upper required></div>
              <?= form_field('bank_name', ['label' => 'Bank name', 'required' => true, 'col' => 'col-md-4'], $vb('bank_name')) ?>
              <?= form_field('branch', ['label' => 'Branch', 'col' => 'col-md-4'], $vb('branch')) ?>
            </div></div></section>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-government-line"></i></span><div><h6>Tax &amp; identity</h6><small>Aadhaar is checked and only its last 4 digits are stored.</small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <div class="col-md-4"><label class="form-label" for="f_pan">PAN <span class="text-danger">*</span></label><input class="form-control text-uppercase" id="f_pan" name="pan" maxlength="10" value="<?= e($vs('pan')) ?>" placeholder="ABCPE1234F" data-upper required></div>
              <div class="col-md-4"><label class="form-label" for="f_aadhaar">Aadhaar number <span class="text-danger">*</span></label>
                <input class="form-control" id="f_aadhaar" name="aadhaar" inputmode="numeric" maxlength="14" placeholder="<?= !empty($s['aadhaar_last4']) ? 'XXXX XXXX ' . e($s['aadhaar_last4']) . ' (saved)' : '1234 5678 9012' ?>">
                <div class="form-text"><?= !empty($s['aadhaar_last4']) ? '<i class="ri-checkbox-circle-fill text-success"></i> Saved. Re-enter only to change it.' : '12 digits. Only the last 4 are stored.' ?></div></div>
              <?= form_field('tax_regime', ['label' => 'Income-tax regime', 'type' => 'select', 'options' => ['New' => 'New regime (default)', 'Old' => 'Old regime (with deductions)'], 'required' => true, 'col' => 'col-md-4', 'help' => 'You can change it once a year for TDS.'], $vs('tax_regime', 'New')) ?>
            </div></div></section>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-shield-user-line"></i></span><div><h6>Provident fund &amp; ESI</h6><small>Declaration for EPF Form 11. Your UAN is on your previous payslips.</small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?= form_field('prev_pf_member', ['label' => 'Were you a member of EPF before?', 'type' => 'select', 'options' => ['No' => 'No, this is my first job with PF', 'Yes' => 'Yes'], 'required' => true, 'col' => 'col-md-6'], $vs('prev_pf_member')) ?>
              <?= form_field('prev_eps_member', ['label' => 'Were you a member of the pension scheme (EPS)?', 'type' => 'select', 'options' => ['No' => 'No', 'Yes' => 'Yes'], 'col' => 'col-md-6'], $vs('prev_eps_member')) ?>
              <?= form_field('uan', ['label' => 'UAN', 'col' => 'col-md-4', 'maxlength' => 12, 'placeholder' => '12 digits', 'help' => 'Required if you were an EPF member.'], $vs('uan')) ?>
              <?= form_field('prev_pf_number', ['label' => 'Previous PF account number', 'col' => 'col-md-4', 'placeholder' => 'e.g. DLCPM0012345000001234'], $vs('prev_pf_number')) ?>
              <?= form_field('esi_number', ['label' => 'ESI IP number (if any)', 'col' => 'col-md-4', 'maxlength' => 17], $vs('esi_number')) ?>
              <?= form_field('international_worker', ['label' => 'International worker?', 'type' => 'select', 'options' => ['No' => 'No', 'Yes' => 'Yes (foreign passport holder)'], 'col' => 'col-md-4'], $vs('international_worker', 'No')) ?>
            </div></div></section>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-parent-line"></i></span><div><h6>Nominee</h6><small>For PF and gratuity (Form 2 / Form F). You can add more nominees on the EPFO portal.</small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?= form_field('nominee_name', ['label' => 'Nominee name', 'required' => true, 'col' => 'col-md-4'], $vs('nominee_name')) ?>
              <?= form_field('nominee_relation', ['label' => 'Relation', 'type' => 'select', 'options' => ONB_RELATIONS, 'required' => true, 'col' => 'col-md-4'], $vs('nominee_relation')) ?>
              <?= form_field('nominee_dob', ['label' => 'Nominee date of birth', 'type' => 'date', 'col' => 'col-md-4'], $vs('nominee_dob')) ?>
            </div></div></section>
          </fieldset>
          <?php if (!$ro): ?><div class="form-actions sticky-actions"><a class="btn btn-light border" href="<?= e($me('education')) ?>">Back</a><button class="btn btn-primary ms-auto">Save &amp; continue <i class="ri-arrow-right-line"></i></button></div><?php endif; ?>
        </form>

      <?php elseif ($step === 'documents'):
          $docs = onb_documents((int) $o['id']); ?>
        <?= $roNote ?>
        <div class="card"><div class="card-header-lite">Upload your documents <span class="small text-muted fw-normal">PDF, JPG or PNG · up to <?= upload_limit_mb(10) ?> MB each</span></div>
          <?php foreach (onb_document_types() as $key => $t): $d = $docs[$key] ?? null; $req = onb_doc_required($t, $o); ?>
            <div class="onb-doc">
              <span class="onb-doc-icon <?= $d ? 'has' : '' ?>"><i class="<?= $d ? ($d['status'] === 'Verified' ? 'ri-shield-check-fill' : ($d['status'] === 'Rejected' ? 'ri-close-circle-fill' : 'ri-file-check-line')) : 'ri-file-upload-line' ?>"></i></span>
              <div class="grow">
                <div class="fw-semibold"><?= e($t['label']) ?> <?= $req ? '<span class="text-danger">*</span>' : '<span class="small text-muted fw-normal">(optional)</span>' ?></div>
                <div class="small text-muted"><?= e($t['hint']) ?></div>
                <?php if ($d): ?><div class="small mt-1"><a href="<?= e(file_url($d['file'])) ?>" target="_blank" rel="noopener"><i class="ri-attachment-2"></i> <?= e($d['original_name'] ?: basename($d['file'])) ?></a> · <?= badge($d['status'] === 'Pending' ? 'Submitted' : $d['status']) ?>
                  <?php if ($d['status'] === 'Rejected' && $d['review_note']): ?><div class="text-danger mt-1"><i class="ri-error-warning-line"></i> <?= e($d['review_note']) ?></div><?php endif; ?></div><?php endif; ?>
              </div>
              <?php if (!$ro && (!$d || $d['status'] !== 'Verified')): ?>
                <form method="post" enctype="multipart/form-data" action="<?= e(url('onboarding', ['a' => 'upload'])) ?>" class="onb-upload"><?= csrf_field() ?><input type="hidden" name="doc_type" value="<?= e($key) ?>">
                  <label class="btn btn-sm <?= $d && $d['status'] !== 'Rejected' ? 'btn-light border' : 'btn-primary' ?> mb-0"><i class="ri-upload-2-line me-1"></i><?= $d ? 'Replace' : 'Upload' ?>
                    <input type="file" name="file" accept="<?= $key === 'photo' ? '.jpg,.jpeg,.png' : '.pdf,.jpg,.jpeg,.png' ?>" hidden onchange="this.form.submit()"></label>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (!$ro): ?><div class="form-actions"><a class="btn btn-light border" href="<?= e($me('bank')) ?>">Back</a><a class="btn btn-primary ms-auto" href="<?= e($me('esign')) ?>">Continue <i class="ri-arrow-right-line"></i></a></div><?php endif; ?>

      <?php elseif ($step === 'esign'):
          $signed = onb_signatures((int) $o['id']);
          $unsigned = onb_unsigned($o); ?>
        <?= $roNote ?>
        <div class="card card-body small text-muted"><i class="ri-information-line"></i> Read each document and sign it. We record your name, the time, your IP address and a fingerprint (SHA-256) of the exact text you signed, so nobody can change a document after you sign it.</div>
        <?php foreach (onb_sign_docs($o) as $key => $doc):
            $sg = $signed[$key] ?? null;
            $isSigned = !isset($unsigned[$key]); ?>
          <section class="card onb-sign-doc" id="doc-<?= e($key) ?>">
            <div class="card-header-lite"><span><i class="<?= $doc['kind'] === 'policy' ? 'ri-shield-check-line' : 'ri-file-text-line' ?> me-1"></i><?= e($doc['title']) ?> <span class="small text-muted fw-normal">v<?= e(ltrim((string) $doc['version'], 'r')) ?></span></span>
              <?= $isSigned ? '<span class="pill pill-success"><i class="ri-check-line"></i> Signed ' . fdate($sg['signed_at'], 'd M') . '</span>' : '<span class="pill pill-warning">To sign</span>' ?></div>
            <?php if ($isSigned): ?>
              <div class="card-body d-flex gap-3 align-items-center flex-wrap"><img class="onb-sig-thumb" src="<?= e(file_url($sg['signature_file'])) ?>" alt="">
                <div class="grow small text-muted">Signed by <?= e($sg['signed_name']) ?> on <?= fdate($sg['signed_at'], 'd M Y, h:i A') ?></div>
                <a class="btn btn-sm btn-light border" href="<?= e(url('onboarding', ['a' => 'signed', 'id' => $sg['id']])) ?>">View signed copy</a></div>
            <?php else: ?>
              <div class="onb-doc-scroll policy-body" tabindex="0"><?= onb_sign_doc_html($doc, $o) ?></div>
              <?php if (!$ro): ?>
                <form method="post" action="<?= e(url('onboarding', ['a' => 'sign'])) ?>" class="card-body border-top" data-sign-form><?= csrf_field() ?><input type="hidden" name="doc" value="<?= e($key) ?>">
                  <?= onb_sign_fields($o, 'this document', '<i class="ri-quill-pen-line me-1"></i>Sign ' . e($doc['title'])) ?></form>
              <?php endif; ?>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
        <?php if (!$ro): ?><div class="form-actions"><a class="btn btn-light border" href="<?= e($me('documents')) ?>">Back</a><a class="btn btn-primary ms-auto" href="<?= e($me('submit')) ?>">Continue <i class="ri-arrow-right-line"></i></a></div><?php endif; ?>

      <?php else: /* submit / summary */
          $ready = $steps && !array_filter($steps, fn($s, $k) => !$s['done'] && $k !== 'submit', ARRAY_FILTER_USE_BOTH); ?>
        <div class="card"><div class="card-header-lite"><?= $editable ? 'Review & submit' : 'Your onboarding' ?></div>
          <?php foreach ($steps ?? [] as $k => $s): if ($k === 'submit') continue; ?>
            <a class="list-row text-body" href="<?= e($me($k)) ?>"><span class="onb-dot <?= $s['done'] ? 'done' : '' ?>"><i class="<?= $s['done'] ? 'ri-check-line' : $s['icon'] ?>"></i></span>
              <div class="grow"><div class="title"><?= e($s['label']) ?></div><?php if ($s['issues']): ?><div class="sub text-danger"><?= e(implode(' · ', array_slice($s['issues'], 0, 3))) ?><?= count($s['issues']) > 3 ? ' …' : '' ?></div><?php endif; ?></div>
              <?= $s['done'] ? '<span class="pill pill-success">Complete</span>' : '<span class="pill pill-warning">To do</span>' ?></a>
          <?php endforeach; ?>
        </div>
        <?php if ($editable): ?>
          <form method="post" action="<?= e(url('onboarding', ['a' => 'submit'])) ?>" class="card form-block"><?= csrf_field() ?>
            <div class="form-block-head"><span class="fb-icon"><i class="ri-send-plane-2-line"></i></span><div><h6>Declarations</h6><small>Please read and confirm.</small></div></div>
            <div class="form-block-body vstack gap-2">
              <label class="form-check"><input class="form-check-input" type="checkbox" name="decl_true" value="1" required> <span class="form-check-label">I confirm that the information and documents I have provided are true, complete and my own. I understand that false information may lead to withdrawal of the offer or termination of employment.</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="decl_bgv" value="1" required> <span class="form-check-label">I authorise <?= e(setting('company_name')) ?> to verify my identity, education and previous employment, directly or through a background verification agency.</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="decl_data" value="1" required> <span class="form-check-label">I consent to the processing of my personal data for my employment, payroll and statutory registrations (EPF, ESI, income tax) under the Digital Personal Data Protection Act, 2023.</span></label>
              <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                <button class="btn btn-primary btn-lg" <?= $ready ? '' : 'disabled' ?>><i class="ri-send-plane-2-line me-1"></i><?= $o['status'] === 'Correction Requested' ? 'Resubmit to HR' : 'Submit to HR' ?></button>
                <?php if (!$ready): ?><span class="small text-muted">Complete every section above to submit.</span><?php endif; ?>
              </div>
            </div>
          </form>
        <?php else: ?>
          <div class="card card-body"><?= onb_candidate_tracker($o) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      </div>
    </div>
    <?php
    page_end(['js/onboarding.js']);
    return;
}

/** Candidate-facing status tracker after submission. */
function onb_candidate_tracker(array $o): string
{
    $flow = ['Submitted' => 'Form submitted', 'Verified' => 'HR verification', 'Approved' => 'Onboarding approved', 'Joined' => 'Joined'];
    $idx = match ($o['status']) { 'Submitted' => 1, 'Ready to Join', 'Activated' => 3, 'Joined' => 4, default => 0 };
    $html = '<div class="stage-track">';
    $i = 0;
    foreach ($flow as $label) {
        $cls = $i < $idx ? 'done' : ($i === $idx ? 'current' : '');
        $html .= '<span class="st-step ' . $cls . '"><i class="' . ($i < $idx ? 'ri-check-line' : 'ri-circle-fill') . '"></i>' . e($label) . '</span>';
        $i++;
    }
    return $html . '</div>';
}

/* ═════════════════════════ HR: offer workflow ═════════════════════════ */

const EMP_TYPES_ONB = ['Full-time', 'Part-time', 'Contract', 'Intern', 'Consultant'];

/** "12 LPA" / "12.5 lakh" / "1200000" → rupees (null if it can't be read). */
function onb_parse_ctc(?string $s): ?float
{
    $t = strtolower(str_replace(',', '', (string) $s));
    if (!preg_match('/(\d+(?:\.\d+)?)/', $t, $m)) return null;
    $n = (float) $m[1];
    if (preg_match('/l(pa|akh|ac)|\bl\b/', $t)) $n *= 100000;
    elseif (str_contains($t, 'cr')) $n *= 10000000;
    elseif ($n < 1000) return null;
    return $n;
}

/** Fixes the offer letter text (and its fingerprint) at approval: later template edits never change an issued offer. */
function onb_freeze(array $o, ?string $note = null): array
{
    $o['approved_at'] = date('Y-m-d H:i:s');
    $html = onb_offer_html($o);
    // Nothing goes out with a missing value: HR fills in the offer details or edits the letter text first.
    if ($miss = hrd_missing_in($html)) throw new RuntimeException('The letter still has missing details: ' . implode(', ', $miss) . '. Complete the offer details or edit the letter text.');
    if ($o['signatory_id'] && ($sig = sig_find((int) $o['signatory_id'], false))) hrd_event(['signatory_id' => $sig['id']], 'signature_inserted', 'Offer ' . $o['offer_ref'] . ' · ' . $sig['name'] . ' (image on the letter; not an electronic signature)');
    onb_save((int) $o['id'], ['offer_html' => $html, 'offer_hash' => hash('sha256', $html), 'approved_by' => user()['id'], 'approved_at' => $o['approved_at'],
        'approver_note' => $note, 'status' => 'Approved']);
    return onb_find((int) $o['id']);
}

/** Problems that stop an offer from going for approval / out to the candidate. */
function onb_offer_errors(array $o): array
{
    $err = [];
    try { if ($miss = hrd_missing_in(onb_offer_html($o))) $err[] = 'The letter has missing details: ' . implode(', ', $miss) . ' (complete the offer or edit the letter text).'; } catch (RuntimeException $ex) { $err[] = $ex->getMessage(); }
    if ($o['ctc_annual'] === null || (float) $o['ctc_annual'] <= 0) $err[] = 'Enter the annual CTC.';
    if (!$o['designation_id']) $err[] = 'Choose the designation.';
    if (!$o['joining_date']) $err[] = 'Enter the joining date.';
    if (!$o['offer_expires'] || $o['offer_expires'] < date('Y-m-d')) $err[] = 'The "accept by" date has passed. Revise the offer and set a new date.';
    if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'The candidate needs a valid e-mail address.';
    return $err;
}

function onb_send_offer_mail(array $o, string $token, bool $again = false): void
{
    $role = $o['des'] ?: $o['job_title'];
    onb_mail_candidate($o, ($again ? 'Your onboarding link: ' : 'Your offer from ') . setting('company_name') . ' — ' . $role,
        ($again ? "Here is your secure onboarding link again (any earlier link no longer works).\n\n" : 'Congratulations! We are delighted to offer you the position of ' . $role . ' at ' . setting('company_name') . ".\n\n")
        . 'Annual CTC: ' . money($o['ctc_annual']) . "\nDate of joining: " . fdate($o['joining_date'], 'd F Y') . ($o['work_location'] ? "\nLocation: {$o['work_location']}" : '')
        . "\n\nRead your offer letter, accept it and complete your joining formalities online:\n" . onb_link($token) . "\n\n"
        . "For your security we'll confirm your e-mail with a one-time code first. Please don't forward this link."
        . ($o['status'] === 'Offer Sent' ? ' The offer is valid until ' . fdate($o['offer_expires'], 'd F Y') . '.' : ''));
}

if (in_array($a, ['submit_approval', 'approve', 'request_changes', 'send', 'resend', 'revise', 'withdraw'], true) && is_post()) {
    $o = onb_access(int_input('id'));
    $note = mb_substr(trim((string) input('note')), 0, 500);
    $mine = (int) $o['created_by'] === (int) user()['id'];

    if ($a === 'submit_approval') {
        authorize('manage_onboarding');
        onb_require_status($o, ['Draft', 'Changes Requested']);
        if ($err = onb_offer_errors($o)) onb_back($o, 'danger', implode('<br>', array_map('e', $err)));
        onb_save((int) $o['id'], ['status' => 'Pending Approval', 'submitted_at' => date('Y-m-d H:i:s')]);
        onb_log((int) $o['id'], 'submitted_for_approval', $note ?: null);
        $link = site_origin() . onb_url($o);
        $n = 0;
        foreach (onb_approvers() as $ap) {
            if ((int) $ap['id'] === (int) user()['id']) continue;
            send_mail($ap['email'], "Offer approval needed: {$o['full_name']} ({$o['des']})", "An offer is waiting for your approval.\n\nCandidate: {$o['full_name']}\nPosition: {$o['des']}" . ($o['dept'] ? ", {$o['dept']}" : '')
                . "\nAnnual CTC: " . money($o['ctc_annual']) . "\nJoining: " . fdate($o['joining_date']) . ($note ? "\nNote: $note" : '') . "\n\nReview and approve: $link\n\n— " . setting('company_name') . ' HRMS');
            $n++;
        }
        onb_back($o, 'success', 'Offer sent for approval' . ($n ? " ($n approver" . ($n > 1 ? 's' : '') . ' notified).' : '.'));
    }

    if ($a === 'approve' || $a === 'request_changes') {
        authorize('approve_offers');
        onb_require_status($o, ['Pending Approval']);
        if ($mine && !is_super_admin()) onb_back($o, 'warning', 'You prepared this offer, so someone else has to approve it.');
        if ($a === 'request_changes') {
            if ($note === '') onb_back($o, 'danger', 'Tell HR what to change.');
            onb_save((int) $o['id'], ['status' => 'Changes Requested', 'approver_note' => $note]);
            onb_log((int) $o['id'], 'changes_requested', $note);
            onb_notify_hr($o, 'changes requested on the offer', user()['display_name'] . " asked for changes to the offer:\n\n$note");
            onb_back($o, 'success', 'Sent back to HR with your comments.');
        }
        try {
            $o = onb_freeze($o, $note ?: null);
        } catch (RuntimeException $ex) {
            onb_back($o, 'danger', e($ex->getMessage()));
        }
        onb_log((int) $o['id'], 'offer_approved', $note ?: null);
        onb_notify_hr($o, 'offer approved', user()['display_name'] . ' approved the offer' . ($note ? ":\n\n$note" : '.') . "\n\nYou can now send it to the candidate.");
        onb_back($o, 'success', 'Offer approved. HR can now send it to the candidate.');
    }

    if ($a === 'send') {
        authorize('manage_onboarding');
        $direct = !onb_approval_required() && in_array($o['status'], ['Draft', 'Changes Requested'], true);
        if (!$direct) onb_require_status($o, ['Approved']);
        if ($err = onb_offer_errors($o)) onb_back($o, 'danger', implode('<br>', array_map('e', $err)));
        if ($direct) {
            try {
                $o = onb_freeze($o);
            } catch (RuntimeException $ex) {
                onb_back($o, 'danger', e($ex->getMessage()));
            }
            onb_log((int) $o['id'], 'offer_approved', 'Approval step switched off (Settings → Onboarding)');
        }
        $token = onb_issue_token($o);
        onb_save((int) $o['id'], ['status' => 'Offer Sent', 'sent_at' => date('Y-m-d H:i:s'), 'sent_count' => (int) $o['sent_count'] + 1]);
        $o = onb_find((int) $o['id']);
        onb_send_offer_mail($o, $token);
        onb_log((int) $o['id'], 'offer_sent', $o['email']);
        onb_candidate_stage($o, 'Offered', 'Offer ' . $o['offer_ref'] . ' sent', ['offer_ctc' => mb_substr(money($o['ctc_annual']) . ' p.a.', 0, 60), 'joining_date' => $o['joining_date']]);
        log_activity('offer_sent', 'onboarding', (int) $o['id'], $o['full_name']);
        onb_back($o, 'success', 'Offer e-mailed to <strong>' . e($o['email']) . '</strong> with a secure onboarding link.');
    }

    if ($a === 'resend') {
        authorize('manage_onboarding');
        onb_require_status($o, ['Offer Sent', 'Accepted', 'Correction Requested']);
        $token = onb_issue_token($o);
        onb_save((int) $o['id'], ['sent_count' => (int) $o['sent_count'] + 1]);
        onb_send_offer_mail($o, $token, true);
        onb_log((int) $o['id'], 'offer_resent', $o['email']);
        onb_back($o, 'success', 'A new secure link was e-mailed to ' . e($o['email']) . '. The previous link no longer works.');
    }

    if ($a === 'revise') {
        authorize('manage_onboarding');
        onb_require_status($o, ['Approved', 'Offer Sent', 'Expired', 'Declined', 'Pending Approval']);
        $wasSent = (bool) $o['sent_at'] && in_array($o['status'], ['Offer Sent', 'Expired'], true);
        onb_save((int) $o['id'], ['status' => 'Draft', 'revision' => (int) $o['revision'] + 1, 'offer_html' => null, 'offer_hash' => null, 'approved_by' => null, 'approved_at' => null,
            'approver_note' => null, 'submitted_at' => null, 'token_hash' => null, 'token_expires' => null, 'declined_at' => null, 'decline_reason' => null]);
        onb_log((int) $o['id'], 'offer_revised', $note ?: ('Was ' . $o['status']));
        if ($wasSent) onb_mail_candidate($o, 'Your offer is being updated', "We are updating your offer letter. The link we sent earlier no longer works; you'll receive the revised offer by e-mail shortly.");
        onb_back($o, 'success', 'The offer is open for changes (revision ' . ((int) $o['revision'] + 1) . '). It needs ' . (onb_approval_required() ? 'approval' : 'to be sent') . ' again.', url('onboarding', ['a' => 'edit', 'id' => $o['id']]));
    }

    if ($a === 'withdraw') {
        authorize('manage_onboarding');
        if (in_array($o['status'], ['Activated', 'Joined', 'Withdrawn'], true)) onb_back($o, 'warning', 'This case can no longer be withdrawn' . ($o['employee_id'] ? '; use Offboard on the employee record instead.' : '.'));
        if ($note === '') onb_back($o, 'danger', 'Give a reason for withdrawing the offer.');
        onb_save((int) $o['id'], ['status' => 'Withdrawn', 'withdrawn_reason' => $note, 'token_hash' => null, 'token_expires' => null]);
        onb_log((int) $o['id'], 'withdrawn', $note);
        onb_candidate_stage($o, 'Rejected', 'Offer withdrawn: ' . $note);
        if ($o['sent_at']) onb_mail_candidate($o, 'Update on your offer from ' . setting('company_name'), "We regret to inform you that the offer for the position of {$o['des']} has been withdrawn. The onboarding link is no longer active.");
        onb_back($o, 'success', 'Offer withdrawn.');
    }
}

/* ═════════════════════════ HR: verification, compliance, approval, joining ═════════════════════════ */

if (in_array($a, ['doc_verify', 'doc_reject'], true) && is_post()) {
    authorize('manage_onboarding');
    $d = one('SELECT * FROM hrm_onboarding_documents WHERE id = ?', [int_input('doc')]) ?? not_found();
    $o = onb_access((int) $d['onboarding_id']);
    onb_require_status($o, ['Accepted', 'Submitted', 'Correction Requested']);
    $note = mb_substr(trim((string) input('note')), 0, 255);
    if ($a === 'doc_reject' && $note === '') onb_back($o, 'danger', 'Say why the document is rejected, so the candidate knows what to upload.', onb_url($o, ['tab' => 'documents']));
    q('UPDATE hrm_onboarding_documents SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$a === 'doc_verify' ? 'Verified' : 'Rejected', $note ?: null, user()['id'], $d['id']]);
    onb_log((int) $o['id'], $a === 'doc_verify' ? 'document_verified' : 'document_rejected', $d['title'] . ($note ? ": $note" : ''));
    onb_back($o, 'success', e($d['title']) . ($a === 'doc_verify' ? ' verified.' : ' rejected. Use "Request corrections" to notify the candidate.'), onb_url($o, ['tab' => 'documents']));
}

if ($a === 'request_corrections' && is_post()) {
    authorize('manage_onboarding');
    $o = onb_access(int_input('id'));
    onb_require_status($o, ['Submitted']);
    $note = mb_substr(trim((string) input('note')), 0, 1000);
    [, $rejected] = onb_document_gaps($o);
    if (!$rejected && $note === '') onb_back($o, 'danger', 'Reject a document or describe what needs to be corrected.', onb_url($o, ['tab' => 'documents']));
    $lines = [];
    foreach (onb_documents((int) $o['id']) as $d) {
        if ($d['status'] === 'Rejected') $lines[] = '- ' . $d['title'] . ($d['review_note'] ? ': ' . $d['review_note'] : '');
    }
    $full = trim(($lines ? "Documents to upload again:\n" . implode("\n", $lines) : '') . ($note !== '' ? "\n\n$note" : ''));
    onb_save((int) $o['id'], ['status' => 'Correction Requested', 'return_note' => $full, 'returned_at' => date('Y-m-d H:i:s')]);
    onb_log((int) $o['id'], 'correction_requested', $full);
    onb_mail_candidate($o, 'Action needed: corrections to your onboarding form', "Our HR team reviewed your onboarding form and needs a few corrections:\n\n$full\n\nSign in to make the changes and submit again: " . site_origin() . url('onboarding', ['a' => 'me']));
    onb_back($o, 'success', 'The candidate has been asked to make corrections.');
}

if ($a === 'compliance' && is_post()) {
    authorize('manage_onboarding');
    $o = onb_access(int_input('id'));
    onb_require_status($o, ['Accepted', 'Submitted', 'Correction Requested', 'Ready to Join']);
    $data = $o['compliance_data'];
    $changed = [];
    foreach (onb_compliance_items() as $k => $t) {
        $done = !empty($_POST['done'][$k]);
        $na = !$done && !empty($_POST['na'][$k]);
        $prev = $data[$k] ?? [];
        if ((bool) ($prev['done'] ?? false) !== $done || (bool) ($prev['na'] ?? false) !== $na) {
            $data[$k] = ['done' => $done, 'na' => $na, 'by' => user()['display_name'], 'at' => date('Y-m-d H:i:s')];
            $changed[] = $t['label'] . ': ' . ($done ? 'done' : ($na ? 'not applicable' : 'open'));
        }
    }
    onb_save((int) $o['id'], ['compliance' => $data]);
    if ($changed) onb_log((int) $o['id'], 'compliance_updated', implode('; ', $changed));
    onb_back($o, 'success', 'Compliance checklist saved.', onb_url($o, ['tab' => 'verify']));
}

if ($a === 'final_approve' && is_post()) {
    authorize('manage_onboarding');
    $o = onb_access(int_input('id'));
    if ($blockers = onb_approval_blockers($o)) onb_back($o, 'danger', 'Not ready for approval:<br>' . implode('<br>', array_map('e', $blockers)), onb_url($o, ['tab' => 'verify']));
    onb_save((int) $o['id'], ['hr_approved_by' => user()['id'], 'hr_approved_at' => date('Y-m-d H:i:s'), 'status' => 'Ready to Join']);
    onb_log((int) $o['id'], 'hr_approved', mb_substr(trim((string) input('note')), 0, 500) ?: null);
    $o = onb_find((int) $o['id']);
    if (onb_activation_trigger() === 'approval') {
        try {
            $empId = onb_activate($o);
        } catch (Throwable $ex) {
            onb_save((int) $o['id'], ['hr_approved_by' => null, 'hr_approved_at' => null, 'status' => 'Submitted']);
            onb_back($o, 'danger', 'The employee could not be created, so the approval was not saved: ' . e($ex->getMessage()), onb_url($o, ['tab' => 'verify']));
        }
        $code = val('SELECT emp_code FROM hrm_employees WHERE id = ?', [$empId]);
        onb_back($o, 'success', 'Onboarding approved. Employee ID <strong>' . e($code) . '</strong> generated and the profile is active. Plan the joining &amp; induction next.', onb_url($o, ['tab' => 'joining']));
    }
    onb_mail_candidate($o, 'Your onboarding is approved', 'Your documents are verified and your onboarding is approved. We look forward to seeing you on ' . fdate($o['joining_date'], 'd F Y') . '. Your employee ID will be issued on your joining day.');
    onb_back($o, 'success', 'Onboarding approved. The employee ID will be generated when joining is confirmed.', onb_url($o, ['tab' => 'joining']));
}

if ($a === 'joining' && is_post()) {
    authorize('manage_onboarding');
    $o = onb_access(int_input('id'));
    onb_require_status($o, ['Ready to Join', 'Activated']);
    $joined = valid_date(input('joined_on')) ?? $o['joining_date'];
    $induction = input('induction_at') ? date('Y-m-d H:i:s', strtotime((string) input('induction_at'))) : null;
    $buddy = int_input('buddy_id') ?: null;
    $access = [];
    foreach (onb_access_items() as $i => $label) {
        if (!empty($_POST['access'][$i])) $access[$label] = $o['access_data'][$label] ?? ['by' => user()['display_name'], 'at' => date('Y-m-d H:i:s')];
    }
    $trainings = array_values(array_filter(array_map('intval', (array) ($_POST['trainings'] ?? []))));
    $fields = ['joined_on' => $joined, 'induction_at' => $induction, 'buddy_id' => $buddy, 'induction_notes' => mb_substr(trim((string) input('induction_notes')), 0, 2000) ?: null, 'access_checklist' => $access];
    if (input('do') !== 'confirm') {
        onb_save((int) $o['id'], $fields);
        onb_log((int) $o['id'], 'induction_saved', null);
        onb_back($o, 'success', 'Joining &amp; induction plan saved.', onb_url($o, ['tab' => 'joining']));
    }
    if ($joined > date('Y-m-d')) onb_back($o, 'danger', 'Joining can be confirmed on or after the joining day (' . fdate($joined) . '). Save the plan for now.', onb_url($o, ['tab' => 'joining']));
    onb_save((int) $o['id'], $fields + ['joining_date' => $joined]);
    $o = onb_find((int) $o['id']);
    try {
        $empId = onb_activate($o);
    } catch (Throwable $ex) {
        onb_back($o, 'danger', 'The employee could not be created: ' . e($ex->getMessage()), onb_url($o, ['tab' => 'joining']));
    }
    $emp = one('SELECT * FROM hrm_employees WHERE id = ?', [$empId]);
    if ($emp['join_date'] !== $joined) {
        q('UPDATE hrm_employees SET join_date = ? WHERE id = ?', [$joined, $empId]);
        $emp['join_date'] = $joined;
        if ($emp['status'] === 'On Probation') probation_start($emp);
    }
    [$tasks, $enrolled] = onb_assign_induction($empId, $joined, $trainings, !empty($_POST['create_tasks']));
    onb_save((int) $o['id'], ['status' => 'Joined', 'joined_by' => user()['id']]);
    onb_log((int) $o['id'], 'joined', 'Joined on ' . fdate($joined) . ($tasks ? " · $tasks induction task(s)" : '') . ($enrolled ? " · $enrolled training(s)" : ''));
    onb_candidate_stage($o, 'Hired', 'Joined on ' . fdate($joined) . ' as ' . $emp['emp_code'], ['joining_date' => $joined]);
    $buddyRow = $buddy ? one('SELECT first_name, last_name, email FROM hrm_employees WHERE id = ?', [$buddy]) : null;
    onb_mail_candidate($o, 'Welcome to ' . setting('company_name') . '!', "Welcome to the team! Your joining is confirmed.\n\nEmployee ID: {$emp['emp_code']}"
        . ($induction ? "\nInduction: " . fdate($induction, 'l, d M Y, h:i A') : '') . ($buddyRow ? "\nYour onboarding buddy: " . emp_name($buddyRow) . ($buddyRow['email'] ? " ({$buddyRow['email']})" : '') : '')
        . ($tasks ? "\n\nYou have $tasks onboarding task(s) under My Tasks in the HRMS." : '') . "\n\nSign in: " . site_origin() . root_url('sign-in.php'));
    if ($o['mgr_email']) send_mail($o['mgr_email'], "New team member: {$o['full_name']} has joined", "{$o['full_name']} ({$emp['emp_code']}) joined as {$o['des']} and reports to you.\n\n" . site_origin() . url('employees', ['a' => 'view', 'id' => $empId]) . "\n\n— " . setting('company_name') . ' HRMS');
    onb_back($o, 'success', e($o['full_name']) . ' has joined. Welcome e-mail sent' . ($tasks ? ", $tasks induction task(s) assigned" : '') . ($enrolled ? ", enrolled in $enrolled training(s)" : '') . '.', onb_url($o, ['tab' => 'joining']));
}

/* ═════════════════════════ HR: offer form (final selection → offer letter) ═════════════════════════ */

if ($a === 'create' || $a === 'edit') {
    authorize('manage_onboarding');
    $o = $a === 'edit' ? onb_access(int_input('id')) : null;
    if ($o && !in_array($o['status'], ['Draft', 'Changes Requested'], true)) onb_back($o, 'warning', 'Reopen the offer for revision first (Revise offer).');
    $candidates = $o ? [] : array_column(all("SELECT c.id, c.name, c.email, c.phone, c.stage, c.offer_ctc, c.joining_date, c.job_id, c.talent_id, j.title job, j.department_id, j.location, j.employment_type
        FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id
        WHERE c.stage IN ('Selected','Offered') AND NOT EXISTS (SELECT 1 FROM hrm_onboardings o WHERE o.candidate_id = c.id AND o.status NOT IN ('Declined','Withdrawn'))
        ORDER BY COALESCE(c.stage_changed_at, c.updated_at, c.created_at) DESC"), null, 'id');
    $offerTemplates = array_column(onb_templates('offer'), 'name', 'id');
    // Offer templates: onboarding text templates and Word templates of type "Offer Letter" from the HR Document Centre.
    $libTemplates = table_exists('hrm_hr_templates') ? array_column(all("SELECT t.id, t.name FROM hrm_hr_templates t JOIN hrm_hr_doc_types ty ON ty.id = t.type_id WHERE ty.name = 'Offer Letter' AND t.status = 'Active' AND t.deleted_at IS NULL AND t.approval_status <> 'Pending' ORDER BY t.name"), 'name', 'id') : [];
    $tplOptions = [];
    foreach ($offerTemplates as $k => $l) $tplOptions['o' . $k] = $l;
    foreach ($libTemplates as $k => $l) $tplOptions['h' . $k] = 'Document Centre: ' . $l;
    $roles = ['employee' => ROLES['employee'], 'manager' => ROLES['manager'], 'hr' => ROLES['hr'], 'support' => ROLES['support']];

    if (is_post()) {
        $errors = [];
        $cand = null;
        if (!$o) {
            $cand = $candidates[int_input('candidate_id')] ?? null;
            if (!$cand) $errors[] = 'Choose a selected candidate.';
            elseif (!filter_var((string) $cand['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'This candidate has no valid e-mail address. Add it on the candidate record first.';
        }
        $ctc = (float) str_replace(',', '', (string) input('ctc_annual'));
        $join = valid_date(input('joining_date'));
        $exp = valid_date(input('offer_expires'));
        $data = [
            'designation_id' => int_input('designation_id') ?: null, 'department_id' => int_input('department_id') ?: null, 'manager_id' => int_input('manager_id') ?: null,
            'employment_type' => in_array(input('employment_type'), EMP_TYPES_ONB, true) ? input('employment_type') : 'Full-time',
            'work_location' => mb_substr(trim((string) input('work_location')), 0, 120) ?: null, 'ctc_annual' => $ctc > 0 ? round($ctc, 2) : null,
            'joining_date' => $join, 'offer_expires' => $exp, 'probation_track' => in_array(input('probation_track'), ['Junior', 'Senior'], true) ? input('probation_track') : null,
            'login_role' => isset($roles[input('login_role')]) ? input('login_role') : 'employee', 'template_id' => ($tk = (string) input('template_id')) && $tk[0] === 'o' && isset($offerTemplates[(int) substr($tk, 1)]) ? (int) substr($tk, 1) : (ctype_digit($tk) && isset($offerTemplates[(int) $tk]) ? (int) $tk : null),
            'hr_template_id' => $tk !== '' && $tk[0] === 'h' && isset($libTemplates[(int) substr($tk, 1)]) ? (int) substr($tk, 1) : null,
            'signatory_id' => int_input('signatory_id') && sig_find(int_input('signatory_id')) ? int_input('signatory_id') : null,
            'hr_notes' => mb_substr(trim((string) input('hr_notes')), 0, 2000) ?: null,
        ];
        if (!$data['designation_id']) $errors[] = 'Choose the designation.';
        if (!$data['department_id']) $errors[] = 'Choose the department.';
        if (!$data['ctc_annual']) $errors[] = 'Enter the annual CTC in rupees.';
        elseif ($data['ctc_annual'] < 10000 || $data['ctc_annual'] > 100000000) $errors[] = 'The annual CTC looks wrong (enter it in rupees, e.g. 600000).';
        if (!$join) $errors[] = 'Enter the joining date.';
        elseif ($join < date('Y-m-d')) $errors[] = 'The joining date is in the past.';
        if (!$exp) $errors[] = 'Enter the date by which the candidate must accept.';
        elseif ($exp < date('Y-m-d')) $errors[] = 'The "accept by" date is in the past.';
        elseif ($join && $exp > $join) $errors[] = 'The offer must be accepted on or before the joining date.';
        if (!$data['template_id'] && !$data['hr_template_id']) $errors[] = 'Choose an offer letter template.';
        if ($data['manager_id'] && !val('SELECT id FROM hrm_employees WHERE id = ? AND status NOT IN ' . EMP_INACTIVE_SQL, [$data['manager_id']])) $errors[] = 'Choose a current employee as reporting manager.';
        if ($errors) {
            $_SESSION['_old'] = $_POST;
            flash('danger', implode('<br>', array_map('e', $errors)));
            redirect($o ? url('onboarding', ['a' => 'edit', 'id' => $o['id']]) : url('onboarding', ['a' => 'create', 'candidate_id' => int_input('candidate_id') ?: null]));
        }
        $data['salary'] = onb_salary((float) $data['ctc_annual'], !empty($_POST['pf_enabled']));
        if ($o) {
            // HR's edited letter text holds the old values: it is reset when the offer details behind it change.
            $keys = ['designation_id', 'department_id', 'manager_id', 'employment_type', 'work_location', 'ctc_annual', 'joining_date', 'offer_expires', 'template_id', 'hr_template_id', 'signatory_id'];
            $changed = array_filter($keys, fn($k) => (string) ($o[$k] ?? '') !== (string) ($data[$k] ?? ''));
            if ($o['offer_draft'] && $changed) { $data['offer_draft'] = null; flash('warning', 'The offer details changed, so your edited letter text was reset to the template. Edit the letter again if needed.'); }
            onb_save((int) $o['id'], $data);
            onb_log((int) $o['id'], 'offer_updated', money($data['ctc_annual']) . ' · joining ' . fdate($join));
            $id = (int) $o['id'];
        } else {
            $data['salary'] = json_encode($data['salary']);
            $id = insert('hrm_onboardings', ['candidate_id' => $cand['id'], 'job_id' => $cand['job_id'], 'talent_id' => $cand['talent_id'], 'full_name' => $cand['name'],
                'email' => strtolower(trim($cand['email'])), 'phone' => $cand['phone'], 'status' => 'Draft', 'offer_ref' => onb_next_ref(), 'created_by' => user()['id']] + $data);
            onb_log($id, 'created', 'Final selection: ' . $cand['name'] . ($cand['job'] ? ' for ' . $cand['job'] : ''));
            log_activity('created', 'onboarding', $id, $cand['name']);
        }
        flash('success', $o ? 'Offer updated.' : 'Onboarding started. Preview the offer letter, then ' . (onb_approval_required() ? 'send it for approval.' : 'send it to the candidate.'));
        redirect(url('onboarding', ['a' => 'view', 'id' => $id]));
    }

    $pre = $o ?? [];
    $sel = $o ? null : ($candidates[int_input('candidate_id')] ?? null);
    if ($sel) {
        $pre += ['department_id' => $sel['department_id'], 'work_location' => $sel['location'], 'employment_type' => $sel['employment_type'],
            'ctc_annual' => onb_parse_ctc($sel['offer_ctc']), 'joining_date' => $sel['joining_date']];
    }
    $v = fn($k, $d = '') => old($k, $pre[$k] ?? $d);
    $defaultTpl = onb_default_template('offer')['id'] ?? '';
    page_start($o ? 'Edit offer · ' . $o['full_name'] : 'Start onboarding', ['Onboarding' => url('onboarding'), $o ? $o['full_name'] : 'New']);
    if (!$o && !$candidates) {
        echo '<div class="card">';
        empty_state('ri-user-star-line', 'No candidates ready for an offer', 'Move a candidate to the "Selected" stage in the hiring pipeline after the final interview and approval; they appear here.', '<a class="btn btn-primary" href="' . e(url('candidates', ['a' => 'board'])) . '">Open hiring pipeline</a>');
        echo '</div>';
        page_end();
        return;
    }
    ?>
    <form method="post" class="grid grid-3" novalidate>
      <?= csrf_field() ?>
      <div class="span-2 vstack gap-3">
        <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-user-star-line"></i></span><div><h6>Final selection</h6><small>Candidates in the Selected stage after the final interview and approval.</small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?php if ($o): ?>
              <div class="col-12"><div class="person"><?= avatar($o['full_name'], null, 'md') ?><span class="meta"><strong><?= e($o['full_name']) ?></strong><small><?= e($o['email']) ?> · <?= e($o['job_title'] ?: 'No job') ?></small></span></div></div>
            <?php else: ?>
              <div class="col-12"><label class="form-label" for="f_candidate_id">Candidate <span class="text-danger">*</span></label>
                <select class="form-select" id="f_candidate_id" name="candidate_id" required data-cand-select>
                  <option value="">— Select the candidate —</option>
                  <?php foreach ($candidates as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) old('candidate_id', $sel['id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?> data-dept="<?= (int) $c['department_id'] ?>" data-loc="<?= e($c['location']) ?>" data-type="<?= e($c['employment_type']) ?>"
                      data-ctc="<?= e((string) onb_parse_ctc($c['offer_ctc'])) ?>" data-join="<?= e($c['joining_date']) ?>"><?= e($c['name']) ?> · <?= e($c['job'] ?: 'No job') ?> (<?= e($c['stage']) ?>)<?= $c['email'] ? '' : ' — no e-mail!' ?></option>
                  <?php endforeach; ?>
                </select></div>
            <?php endif; ?>
          </div></div></section>
        <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-briefcase-4-line"></i></span><div><h6>Position</h6></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?= form_field('designation_id', ['label' => 'Designation', 'type' => 'relation', 'table' => 'hrm_designations', 'label_col' => 'title', 'required' => true, 'col' => 'col-md-6'], $v('designation_id')) ?>
            <?= form_field('department_id', ['label' => 'Department', 'type' => 'relation', 'table' => 'hrm_departments', 'required' => true, 'col' => 'col-md-6'], $v('department_id')) ?>
            <?= form_field('manager_id', ['label' => 'Reporting manager', 'type' => 'select', 'options' => employee_options(), 'col' => 'col-md-6'], $v('manager_id')) ?>
            <?= form_field('employment_type', ['label' => 'Employment type', 'type' => 'select', 'options' => EMP_TYPES_ONB, 'required' => true, 'col' => 'col-md-6'], $v('employment_type', 'Full-time')) ?>
            <?= form_field('work_location', ['label' => 'Work location', 'col' => 'col-md-6', 'placeholder' => 'e.g. Noida office / Remote'], $v('work_location')) ?>
            <?= form_field('probation_track', ['label' => 'Probation', 'type' => 'select', 'options' => ['Junior' => 'Junior profile — ' . probation_months('Junior') . ' months', 'Senior' => 'Senior profile — ' . probation_months('Senior') . ' months'], 'placeholder' => 'Auto (from designation level)', 'col' => 'col-md-6'], $v('probation_track')) ?>
          </div></div></section>
        <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-money-rupee-circle-line"></i></span><div><h6>Compensation</h6><small>Annual CTC including employer PF. The break-up follows Settings → Onboarding.</small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="f_ctc">Annual CTC <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text"><?= e(setting('currency', '₹')) ?></span>
              <input class="form-control" type="number" min="10000" step="1" id="f_ctc" name="ctc_annual" value="<?= e($v('ctc_annual')) ?>" required data-ctc-input placeholder="e.g. 600000"></div></div>
            <div class="col-md-6"><div class="form-check form-switch mt-md-4 pt-md-2"><input type="hidden" name="pf_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="f_pf" name="pf_enabled" value="1" data-pf-input <?= old('pf_enabled', $o ? (!empty($o['salary_data']['pf']) ? '1' : '0') : '1') ? 'checked' : '' ?>>
              <label class="form-check-label" for="f_pf">Provident fund (employer share included in CTC)</label></div></div>
            <div class="col-12"><div class="onb-salary-preview" data-salary-preview data-cur="<?= e(setting('currency', '₹')) ?>" data-basic="<?= e(setting('salary_basic_pct', 40)) ?>" data-hra="<?= e(setting('salary_hra_pct', 50)) ?>" data-pfpct="<?= e(setting('pf_percent', 12)) ?>" data-ceiling="<?= e(setting('pf_wage_ceiling', 15000)) ?>"></div></div>
          </div></div></section>
        <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-file-text-line"></i></span><div><h6>Offer letter</h6></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?= form_field('joining_date', ['label' => 'Date of joining', 'type' => 'date', 'required' => true, 'col' => 'col-md-4', 'min' => date('Y-m-d')], $v('joining_date')) ?>
            <?= form_field('offer_expires', ['label' => 'Candidate must accept by', 'type' => 'date', 'required' => true, 'col' => 'col-md-4', 'min' => date('Y-m-d')], $v('offer_expires', date('Y-m-d', strtotime('+' . max(1, (int) setting('offer_valid_days', 7)) . ' days')))) ?>
            <?= form_field('template_id', ['label' => 'Template', 'type' => 'select', 'options' => $tplOptions, 'required' => true, 'col' => 'col-md-4'], (string) old('template_id', $o ? ($o['hr_template_id'] ? 'h' . $o['hr_template_id'] : 'o' . $o['template_id']) : 'o' . $defaultTpl)) ?>
            <?= form_field('signatory_id', ['label' => 'Authorised signatory', 'type' => 'select', 'options' => sig_options(), 'placeholder' => '— None (no signature image) —', 'col' => 'col-md-4', 'help' => 'Places the signatory\'s signature image on the letter when it is approved. Manage under HR Document Centre → Signatures.'], $v('signatory_id')) ?>
            <?= form_field('login_role', ['label' => 'HRMS role after joining', 'type' => 'select', 'options' => $roles, 'col' => 'col-md-4', 'help' => 'Decides their module access.'], $v('login_role', 'employee')) ?>
            <?= form_field('hr_notes', ['label' => 'Internal notes (not shown to the candidate)', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-md-8', 'placeholder' => 'Negotiation notes, approvals received, joining bonus discussion…'], $v('hr_notes')) ?>
          </div></div></section>
        <div class="form-actions sticky-actions"><a class="btn btn-light border" href="<?= e($o ? onb_url($o) : url('onboarding')) ?>">Cancel</a>
          <button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i><?= $o ? 'Save offer' : 'Create offer' ?></button></div>
      </div>
      <div class="vstack gap-3">
        <div class="card card-body small"><h6 class="fw-bold"><i class="ri-route-line text-primary me-1"></i>What happens next</h6>
          <ol class="ps-3 mb-0 vstack gap-1">
            <li>Preview the offer letter generated from the template.</li>
            <?php if (onb_approval_required()): ?><li>Send it for approval (someone with <em>Approve offers</em> access).</li><?php endif; ?>
            <li>E-mail it: the candidate gets a secure link, verifies their e-mail with a one-time code and accepts online.</li>
            <li>They fill in their details, upload documents and e-sign; you verify them and approve.</li>
            <li>The employee ID and profile are created automatically <?= onb_activation_trigger() === 'joining' ? 'when you confirm joining' : 'on your final approval' ?>.</li>
          </ol></div>
        <?php if ($o && $o['approver_note'] && $o['status'] === 'Changes Requested'): ?>
          <div class="card card-body small onb-note-warn"><strong><i class="ri-chat-quote-line me-1"></i>Approver's comments</strong><div class="mt-1"><?= nl2br(e($o['approver_note'])) ?></div></div>
        <?php endif; ?>
      </div>
    </form>
    <?php
    page_end(['js/onboarding.js']);
    return;
}

/* ═════════════════════════ HR: case page ═════════════════════════ */

if ($a === 'view') {
    $o = onb_access(int_input('id'));
    $tab = $_GET['tab'] ?? 'overview';
    $tabs = ['overview' => 'Overview', 'details' => 'Candidate details', 'documents' => 'Documents', 'esign' => 'E-signatures', 'verify' => 'Verification & approval', 'joining' => 'Joining & induction'];
    if (!isset($tabs[$tab])) $tab = 'overview';
    $st = $o['status'];
    $mine = (int) $o['created_by'] === (int) user()['id'];
    $canApprove = can('approve_offers') && (!$mine || is_super_admin());
    $docs = onb_documents((int) $o['id']);
    $pendingDocs = count(array_filter($docs, fn($d) => $d['status'] === 'Pending'));
    $pct = onb_percent($o);

    // Header actions for the current status.
    $btn = [];
    $post = fn($act, $label, $class = 'btn btn-light border', $confirm = '', $prompt = '') => str_replace('<button', '<button' . ($prompt ? ' data-prompt="' . e($prompt) . '"' : ''), post_button(url('onboarding', ['a' => $act]), $label, $class, $confirm, ['id' => $o['id']]));
    $btn[] = '<a class="btn btn-light border" href="' . e(url('onboarding', ['a' => 'letter', 'id' => $o['id']])) . '"><i class="ri-file-text-line me-1"></i>' . ($o['offer_html'] ? 'Offer letter' : 'Preview letter') . '</a>';
    if ($canManage && in_array($st, ['Draft', 'Changes Requested'], true)) {
        $btn[] = '<a class="btn btn-light border" href="' . e(url('onboarding', ['a' => 'edit', 'id' => $o['id']])) . '"><i class="ri-pencil-line me-1"></i>Edit offer</a>';
        $btn[] = onb_approval_required() ? $post('submit_approval', '<i class="ri-send-plane-line me-1"></i>Send for approval', 'btn btn-primary', 'Send this offer for approval?')
            : $post('send', '<i class="ri-mail-send-line me-1"></i>Send offer', 'btn btn-primary', 'E-mail the offer and a secure onboarding link to ' . $o['email'] . '?');
    }
    if ($st === 'Pending Approval' && $canApprove) {
        $btn[] = $post('request_changes', '<i class="ri-arrow-go-back-line me-1"></i>Request changes', 'btn btn-outline-warning', '', 'What should HR change in this offer?');
        $btn[] = $post('approve', '<i class="ri-checkbox-circle-line me-1"></i>Approve offer', 'btn btn-success', 'Approve this offer? The letter text is then fixed.');
    }
    if ($canManage && $st === 'Approved') $btn[] = $post('send', '<i class="ri-mail-send-line me-1"></i>Send offer', 'btn btn-primary', 'E-mail the offer and a secure onboarding link to ' . $o['email'] . '?');
    if ($canManage && in_array($st, ['Offer Sent', 'Accepted', 'Correction Requested'], true)) $btn[] = $post('resend', '<i class="ri-mail-send-line me-1"></i>Resend link', 'btn btn-light border', 'Send a new secure link to ' . $o['email'] . '? The old link stops working.');
    $more = [];
    if ($canManage && in_array($st, ['Approved', 'Offer Sent', 'Expired', 'Declined', 'Pending Approval'], true)) $more[] = $post('revise', '<i class="ri-edit-2-line me-2"></i>Revise offer', 'dropdown-item', 'Reopen the offer for changes? It will need ' . (onb_approval_required() ? 'approval' : 'to be sent') . ' again and any link already sent stops working.');
    if ($canManage && !in_array($st, ['Activated', 'Joined', 'Withdrawn'], true)) $more[] = $post('withdraw', '<i class="ri-close-circle-line me-2"></i>Withdraw offer', 'dropdown-item text-danger', '', 'Why is the offer being withdrawn? (The candidate is informed.)');
    if ($o['accepted_at']) $more[] = '<a class="dropdown-item" href="' . e(url('onboarding', ['a' => 'print', 'id' => $o['id']])) . '"><i class="ri-printer-line me-2"></i>Joining form (print)</a>';
    if ($o['candidate_id']) $more[] = '<a class="dropdown-item" href="' . e(url('candidates', ['a' => 'view', 'id' => $o['candidate_id']])) . '"><i class="ri-user-search-line me-2"></i>Candidate record</a>';
    if ($o['employee_id']) $more[] = '<a class="dropdown-item" href="' . e(url('employees', ['a' => 'view', 'id' => $o['employee_id']])) . '"><i class="ri-user-follow-line me-2"></i>Employee profile</a>';
    if ($more) $btn[] = '<div class="dropdown"><button class="btn btn-light border" data-bs-toggle="dropdown" aria-label="More actions"><i class="ri-more-2-fill"></i></button><div class="dropdown-menu dropdown-menu-end shadow-sm">' . implode('', $more) . '</div></div>';

    page_start($o['full_name'], ['Onboarding' => url('onboarding'), $o['full_name']], implode('', $btn));
    $emp = $o['employee_id'] ? one('SELECT id, emp_code, status FROM hrm_employees WHERE id = ?', [$o['employee_id']]) : null;
    ?>
    <div class="card mb-3">
      <div class="card-body onb-head">
        <div class="d-flex gap-3 align-items-center grow"><?= avatar($o['full_name'], null, 'lg') ?>
          <div><h5 class="fw-bold mb-1"><?= e($o['full_name']) ?> <?= badge($st) ?></h5>
            <div class="small text-muted"><?= e($o['des'] ?: '—') ?><?= $o['dept'] ? ' · ' . e($o['dept']) : '' ?> · <?= e($o['offer_ref']) ?><?= (int) $o['revision'] > 1 ? ' (rev ' . (int) $o['revision'] . ')' : '' ?></div>
            <div class="small text-muted"><i class="ri-mail-line"></i> <?= e($o['email']) ?><?= $o['phone'] ? ' · <i class="ri-phone-line"></i> ' . e($o['phone']) : '' ?></div></div></div>
        <div class="onb-head-facts">
          <div><small>Joining</small><strong><?= fdate($o['joining_date'], 'd M Y') ?></strong></div>
          <div><small>Annual CTC</small><strong><?= money($o['ctc_annual']) ?></strong></div>
          <div><small><?= $emp ? 'Employee ID' : 'Form' ?></small><strong><?= $emp ? e($emp['emp_code']) : $pct . '%' ?></strong></div>
        </div>
      </div>
      <?php $flow = onb_workflow($o); ?>
      <div class="onb-flow">
        <?php $n = 0; foreach ($flow as $phase => $steps): $n++; $phaseDone = !array_filter($steps, fn($s) => !$s['done']); ?>
          <div class="onb-phase <?= $phaseDone ? 'done' : '' ?>"><div class="onb-phase-title"><span><?= $n ?></span><?= e($phase) ?></div>
            <ol><?php foreach ($steps as $label => $s): ?><li class="<?= $s['done'] ? 'done' : ($s['current'] ? 'current' : '') ?>"><i class="<?= $s['done'] ? 'ri-checkbox-circle-fill' : ($s['current'] ? 'ri-focus-2-line' : 'ri-checkbox-blank-circle-line') ?>"></i><?= e($label) ?></li><?php endforeach; ?></ol></div>
        <?php endforeach; ?>
      </div>
      <nav class="nav-tabs-lite">
        <?php foreach ($tabs as $k => $label): $cnt = $k === 'documents' && $pendingDocs ? ' <span class="nav-badge position-static ms-1">' . $pendingDocs . '</span>' : ''; ?>
          <a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(onb_url($o, ['tab' => $k])) ?>"><?= e($label) ?><?= $cnt ?></a>
        <?php endforeach; ?>
      </nav>
    </div>

    <?php if (in_array($st, ['Declined', 'Withdrawn', 'Changes Requested', 'Correction Requested', 'Expired'], true)):
        $msg = ['Declined' => ['danger', 'Offer declined by the candidate on ' . fdate($o['declined_at']) . '.', $o['decline_reason']],
            'Withdrawn' => ['danger', 'Offer withdrawn.', $o['withdrawn_reason']],
            'Changes Requested' => ['warning', 'The approver asked for changes. Edit the offer and send it for approval again.', $o['approver_note']],
            'Correction Requested' => ['warning', 'Waiting for the candidate to make corrections (requested ' . fdate($o['returned_at']) . ').', $o['return_note']],
            'Expired' => ['danger', 'The offer was not accepted by ' . fdate($o['offer_expires']) . '. Revise it to set a new date and send it again.', null]][$st]; ?>
      <div class="alert alert-<?= $msg[0] ?> d-flex gap-2"><i class="ri-information-line fs-5"></i><div><strong><?= e($msg[1]) ?></strong><?= $msg[2] ? '<div class="small mt-1">' . nl2br(e($msg[2])) . '</div>' : '' ?></div></div>
    <?php endif; ?>

    <?php if ($tab === 'overview' && $emp && $canManage): require APP_DIR . '/modules/partials/onb_credentials.php'; endif; ?>
    <?php if ($tab === 'overview' && $canManage) echo hrd_history_card($o['candidate_id'] ? (int) $o['candidate_id'] : null, $o['employee_id'] ? (int) $o['employee_id'] : null); ?>
    <?php if ($tab === 'overview'): $events = onb_events((int) $o['id']); ?>
      <div class="grid grid-3">
        <div class="span-2 vstack gap-3">
          <div class="card"><div class="card-header-lite">Offer</div><div class="card-body"><dl class="detail-grid">
            <div><dt>Designation</dt><dd><?= e($o['des'] ?: '—') ?></dd></div><div><dt>Department</dt><dd><?= e($o['dept'] ?: '—') ?></dd></div>
            <div><dt>Reporting manager</dt><dd><?= e(trim((string) $o['mgr']) ?: '—') ?></dd></div><div><dt>Employment type</dt><dd><?= e($o['employment_type']) ?></dd></div>
            <div><dt>Work location</dt><dd><?= e($o['work_location'] ?: '—') ?></dd></div><div><dt>Probation</dt><dd><?= probation_months($o['probation_track'] ?: (in_array($o['des_level'] ?? '', SENIOR_LEVELS, true) ? 'Senior' : 'Junior')) ?> months<?= $o['probation_track'] ? '' : ' (auto)' ?></dd></div>
            <div><dt>Joining date</dt><dd><?= fdate($o['joining_date']) ?></dd></div><div><dt>Accept by</dt><dd><?= fdate($o['offer_expires']) ?></dd></div>
            <div><dt>HRMS role after joining</dt><dd><?= e(ROLES[normalize_role($o['login_role'])]) ?></dd></div><div><dt>Job opening</dt><dd><?= e($o['job_title'] ?: '—') ?></dd></div>
          </dl></div></div>
          <div class="card"><div class="card-header-lite">Salary break-up</div><div class="card-body"><?= onb_salary_table($o['salary_data']) ?: '<span class="text-muted">—</span>' ?></div></div>
          <?php if ($o['hr_notes']): ?><div class="card"><div class="card-header-lite">Internal notes</div><div class="card-body small"><?= nl2br(e($o['hr_notes'])) ?></div></div><?php endif; ?>
        </div>
        <div class="vstack gap-3">
          <div class="card"><div class="card-header-lite">Key dates</div><div class="card-body small vstack gap-1">
            <?php foreach (['Created' => $o['created_at'], 'Sent for approval' => $o['submitted_at'], 'Approved' => $o['approved_at'], 'Offer e-mailed' => $o['sent_at'], 'E-mail verified' => $o['email_verified_at'],
                'Accepted' => $o['accepted_at'], 'Form submitted' => $o['form_submitted_at'], 'HR approved' => $o['hr_approved_at'], 'Activated' => $o['activated_at']] as $l => $d): if (!$d) continue; ?>
              <div class="d-flex justify-content-between"><span class="text-muted"><?= e($l) ?></span><span><?= fdate($d, 'd M Y, h:i A') ?></span></div>
            <?php endforeach; ?>
            <?php if ($o['offer_hash']): ?><div class="text-muted mt-2" title="SHA-256 of the issued letter">Letter fingerprint <code class="small"><?= e(substr($o['offer_hash'], 0, 16)) ?>…</code></div><?php endif; ?>
          </div></div>
          <div class="card"><div class="card-header-lite">Activity</div>
            <ul class="timeline history card-body m-0">
              <?php foreach (array_slice($events, 0, 25) as $ev): ?>
                <li><div class="small"><strong><?= e(ONB_EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></strong><?= $ev['note'] ? ' · <span class="text-muted">' . e(mb_strimwidth($ev['note'], 0, 140, '…')) . '</span>' : '' ?></div>
                  <div class="small text-muted"><?= $ev['actor'] === 'candidate' ? 'Candidate' : ($ev['actor'] === 'system' ? 'System' : e($ev['username'] ?? 'HR')) ?> · <?= time_ago($ev['created_at']) ?></div></li>
              <?php endforeach; ?>
            </ul></div>
        </div>
      </div>

    <?php elseif ($tab === 'details'):
        $pr = $o['profile_data'];
        $p = $pr['personal'] ?? [];
        $b = $pr['bank'] ?? [];
        $s = $pr['statutory'] ?? [];
        $dd = fn($l, $v) => '<div><dt>' . e($l) . '</dt><dd>' . ($v !== null && $v !== '' ? e($v) : '<span class="text-muted">—</span>') . '</dd></div>';
        if (!$o['accepted_at']): ?>
        <div class="card"><?php empty_state('ri-user-3-line', 'Nothing yet', 'The candidate fills in their details after accepting the offer.'); ?></div>
      <?php else: ?>
        <div class="grid grid-2">
          <div class="card"><div class="card-header-lite">Personal <?= onb_step_status($o)['personal']['done'] ? '<span class="pill pill-success">Complete</span>' : '<span class="pill pill-warning">Incomplete</span>' ?></div><div class="card-body"><dl class="detail-grid">
            <?= $dd('Name', trim(($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))) . $dd('Father\'s / spouse\'s name', $p['father_name'] ?? '') . $dd('Date of birth', !empty($p['dob']) ? fdate($p['dob']) . ' (' . onb_age($p['dob']) . ' yrs)' : '')
              . $dd('Gender', $p['gender'] ?? '') . $dd('Marital status', $p['marital_status'] ?? '') . $dd('Blood group', $p['blood_group'] ?? '') . $dd('Nationality', $p['nationality'] ?? '')
              . $dd('Mobile', trim(($p['phone'] ?? '') . (!empty($p['alt_phone']) ? ' / ' . $p['alt_phone'] : ''))) . $dd('Personal e-mail', $p['personal_email'] ?? '')
              . $dd('Current address', trim(implode(', ', array_filter([$p['current_address'] ?? '', $p['current_city'] ?? '', $p['current_state'] ?? '', $p['current_pincode'] ?? '']))))
              . $dd('Permanent address', !empty($p['permanent_same']) ? 'Same as current' : trim(implode(', ', array_filter([$p['permanent_address'] ?? '', $p['permanent_city'] ?? '', $p['permanent_state'] ?? '', $p['permanent_pincode'] ?? '']))))
              . $dd('Emergency contact', trim(($p['emergency_name'] ?? '') . (!empty($p['emergency_relation']) ? ' (' . $p['emergency_relation'] . ')' : '') . ' ' . ($p['emergency_phone'] ?? ''))) ?>
          </dl></div></div>
          <div class="card"><div class="card-header-lite">Bank &amp; statutory <?= onb_step_status($o)['bank']['done'] ? '<span class="pill pill-success">Complete</span>' : '<span class="pill pill-warning">Incomplete</span>' ?></div><div class="card-body"><dl class="detail-grid">
            <?= $dd('Account holder', $b['account_holder'] ?? '') . $dd('Bank', trim(($b['bank_name'] ?? '') . (!empty($b['branch']) ? ', ' . $b['branch'] : ''))) . $dd('Account number', $b['account_number'] ?? '') . $dd('IFSC', $b['ifsc'] ?? '') . $dd('Account type', $b['account_type'] ?? '')
              . $dd('PAN', $s['pan'] ?? '') . $dd('Aadhaar', !empty($s['aadhaar_last4']) ? 'XXXX XXXX ' . $s['aadhaar_last4'] . ' (checksum valid)' : '') . $dd('Tax regime', $s['tax_regime'] ?? '')
              . $dd('Previous EPF member', $s['prev_pf_member'] ?? '') . $dd('Previous EPS member', $s['prev_eps_member'] ?? '') . $dd('UAN', $s['uan'] ?? '') . $dd('Previous PF account', $s['prev_pf_number'] ?? '')
              . $dd('ESI IP number', $s['esi_number'] ?? '') . $dd('International worker', $s['international_worker'] ?? '')
              . $dd('Nominee', trim(($s['nominee_name'] ?? '') . (!empty($s['nominee_relation']) ? ' (' . $s['nominee_relation'] . ')' : '') . (!empty($s['nominee_dob']) ? ', born ' . fdate($s['nominee_dob']) : ''))) ?>
          </dl></div></div>
          <div class="card span-2"><div class="card-header-lite">Education</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Qualification</th><th>Specialisation</th><th>Institution</th><th>Board / university</th><th>Year</th><th>Score</th></tr></thead><tbody>
            <?php foreach ($pr['education'] ?? [] as $r): ?><tr><td><?= e($r['qualification']) ?></td><td><?= e($r['specialization']) ?></td><td><?= e($r['institution']) ?></td><td><?= e($r['board']) ?></td><td><?= e($r['year']) ?></td><td><?= e($r['score']) ?></td></tr><?php endforeach; ?>
            <?php if (empty($pr['education'])): ?><tr><td colspan="6" class="text-muted text-center py-3">Not filled in yet.</td></tr><?php endif; ?>
          </tbody></table></div></div>
          <div class="card span-2"><div class="card-header-lite">Employment history <span class="tag"><?= e($pr['experience_type'] ?? '—') ?></span></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Employer</th><th>Designation</th><th>From</th><th>To</th><th>Last CTC</th><th>Reason for leaving</th></tr></thead><tbody>
            <?php foreach ($pr['employment'] ?? [] as $r): ?><tr><td><?= e($r['employer']) ?></td><td><?= e($r['designation']) ?></td><td><?= fdate($r['from'], 'M Y') ?></td><td><?= $r['to'] ? fdate($r['to'], 'M Y') : 'Present' ?></td><td><?= e($r['ctc']) ?></td><td class="small"><?= e($r['reason']) ?></td></tr><?php endforeach; ?>
            <?php if (empty($pr['employment'])): ?><tr><td colspan="6" class="text-muted text-center py-3"><?= ($pr['experience_type'] ?? '') === 'Fresher' ? 'Fresher: no previous employment.' : 'Not filled in yet.' ?></td></tr><?php endif; ?>
          </tbody></table></div></div>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'documents'):
        $canReview = $canManage && in_array($st, ['Accepted', 'Submitted', 'Correction Requested'], true);
        $history = all("SELECT * FROM hrm_onboarding_documents WHERE onboarding_id = ? AND status = 'Replaced' ORDER BY id DESC", [$o['id']]); ?>
      <div class="card">
        <div class="card-header-lite">Documents <span class="small text-muted fw-normal"><?= count(array_filter($docs, fn($d) => $d['status'] === 'Verified')) ?> verified · <?= $pendingDocs ?> to review</span>
          <?php if ($canManage && $st === 'Submitted'): ?><?= str_replace('<button', '<button data-prompt="Anything else the candidate should correct? (Rejected documents are listed automatically.)"', post_button(url('onboarding', ['a' => 'request_corrections']), '<i class="ri-arrow-go-back-line me-1"></i>Request corrections', 'btn btn-sm btn-outline-warning', '', ['id' => $o['id']])) ?><?php endif; ?></div>
        <?php foreach (onb_document_types() as $key => $t): $d = $docs[$key] ?? null; $req = onb_doc_required($t, $o); ?>
          <div class="onb-doc">
            <span class="onb-doc-icon <?= $d ? 'has' : '' ?>"><i class="<?= $d ? 'ri-file-text-line' : 'ri-file-forbid-line' ?>"></i></span>
            <div class="grow"><div class="fw-semibold"><?= e($t['label']) ?> <?= $req ? '<span class="text-danger" title="Required">*</span>' : '<span class="small text-muted fw-normal">(optional)</span>' ?></div>
              <?php if ($d): ?>
                <div class="small"><a href="<?= e(file_url($d['file'])) ?>" target="_blank" rel="noopener"><i class="ri-external-link-line"></i> <?= e($d['original_name'] ?: basename($d['file'])) ?></a> · uploaded <?= time_ago($d['uploaded_at']) ?>
                  <?= $d['reviewed_at'] ? ' · ' . e($d['status']) . ' by ' . e($d['reviewer'] ?? 'HR') . ' ' . time_ago($d['reviewed_at']) : '' ?></div>
                <?php if ($d['review_note']): ?><div class="small text-danger"><?= e($d['review_note']) ?></div><?php endif; ?>
              <?php else: ?><div class="small text-muted">Not uploaded<?= $req ? '' : ' (optional)' ?>.</div><?php endif; ?>
            </div>
            <?= $d ? badge($d['status']) : '' ?>
            <?php if ($d && $canReview): ?>
              <div class="d-flex gap-1">
                <?php if ($d['status'] !== 'Verified'): ?><?= post_button(url('onboarding', ['a' => 'doc_verify']), '<i class="ri-check-line"></i> Verify', 'btn btn-sm btn-soft-success', '', ['doc' => $d['id']]) ?><?php endif; ?>
                <?php if ($d['status'] !== 'Rejected'): ?><?= str_replace('<button', '<button data-prompt="Why is this document rejected? The candidate sees this note."', post_button(url('onboarding', ['a' => 'doc_reject']), '<i class="ri-close-line"></i> Reject', 'btn btn-sm btn-outline-danger', '', ['doc' => $d['id']])) ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($history): ?>
        <details class="card card-body mt-3 small"><summary>Earlier uploads (<?= count($history) ?>)</summary>
          <ul class="mt-2 mb-0"><?php foreach ($history as $h): ?><li><a href="<?= e(file_url($h['file'])) ?>" target="_blank" rel="noopener"><?= e($h['title']) ?></a> · <?= fdate($h['uploaded_at'], 'd M Y, h:i A') ?><?= $h['review_note'] ? ' · ' . e($h['review_note']) : '' ?></li><?php endforeach; ?></ul></details>
      <?php endif; ?>

    <?php elseif ($tab === 'esign'):
        $signed = onb_signatures((int) $o['id']);
        $unsigned = onb_unsigned($o); ?>
      <div class="card"><div class="card-header-lite">E-signatures <span class="small text-muted fw-normal">Name, time, IP, device and the SHA-256 of the exact text are recorded for each signature.</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Document</th><th>Version</th><th>Status</th><th>Signed by</th><th>When / where</th><th></th></tr></thead><tbody>
          <?php foreach (onb_sign_docs($o) as $key => $doc): $sg = $signed[$key] ?? null; $ok = !isset($unsigned[$key]); ?>
            <tr><td class="fw-semibold"><?= e($doc['title']) ?> <span class="small text-muted fw-normal"><?= e(['offer' => 'Offer', 'agreement' => 'Agreement', 'nda' => 'NDA', 'policy' => 'HR policy'][$doc['kind']]) ?></span></td>
              <td><?= e(ltrim((string) $doc['version'], 'r')) ?></td><td><?= $ok ? badge('Signed') : ($sg ? '<span class="pill pill-warning">Re-sign: new version</span>' : '<span class="pill pill-neutral">Not signed</span>') ?></td>
              <td><?= $sg ? '<img class="onb-sig-thumb me-2" src="' . e(file_url($sg['signature_file'])) . '" alt="">' . e($sg['signed_name']) : '—' ?></td>
              <td class="small text-muted"><?= $sg ? fdate($sg['signed_at'], 'd M Y, h:i A') . '<br>' . e($sg['ip']) . ' · ' . e(device_label((string) $sg['user_agent'])) : '' ?></td>
              <td class="text-end"><?php if ($sg): ?><a class="btn btn-sm btn-light border" href="<?= e(url('onboarding', ['a' => 'signed', 'id' => $sg['id']])) ?>">View</a><?php endif; ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>

    <?php elseif ($tab === 'verify'):
        $comp = onb_compliance($o);
        $blockers = onb_approval_blockers($o);
        $editable = $canManage && in_array($st, ['Accepted', 'Submitted', 'Correction Requested', 'Ready to Join'], true); ?>
      <div class="grid grid-3">
        <form method="post" action="<?= e(url('onboarding', ['a' => 'compliance'])) ?>" class="card span-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <div class="card-header-lite">Compliance check</div>
          <?php foreach ($comp as $k => $c): ?>
            <div class="onb-check <?= $c['done'] ? 'done' : '' ?>">
              <?php if ($c['auto']): ?>
                <i class="<?= $c['done'] ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line' ?>"></i>
                <div class="grow"><div><?= e($c['label']) ?></div><div class="small text-muted">Checked automatically</div></div>
              <?php else: ?>
                <input class="form-check-input mt-1" type="checkbox" id="cmp_<?= e($k) ?>" name="done[<?= e($k) ?>]" value="1" <?= $c['done'] ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                <label class="grow" for="cmp_<?= e($k) ?>"><div><?= e($c['label']) ?> <?= $c['required'] ? '<span class="text-danger">*</span>' : '' ?></div>
                  <div class="small text-muted"><?= $c['by'] ? e(($c['done'] ? 'Done' : ($c['na'] ? 'Marked not applicable' : 'Reopened')) . ' by ' . $c['by'] . ' · ' . fdate($c['at'], 'd M, h:i A')) : e($c['hint'] ?: 'Manual check') ?></div></label>
                <?php if (!$c['required']): ?><label class="small text-muted text-nowrap"><input class="form-check-input" type="checkbox" name="na[<?= e($k) ?>]" value="1" <?= $c['na'] ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>> N/A</label><?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($editable): ?><div class="card-body border-top"><button class="btn btn-light border"><i class="ri-save-3-line me-1"></i>Save checklist</button></div><?php endif; ?>
        </form>
        <div class="vstack gap-3">
          <div class="card card-body">
            <h6 class="fw-bold"><i class="ri-shield-check-line text-primary me-1"></i>HR final approval</h6>
            <?php if ($o['hr_approved_at']): ?>
              <p class="small mb-0">Approved on <?= fdate($o['hr_approved_at'], 'd M Y, h:i A') ?>.<?= $emp ? ' Employee ID <strong>' . e($emp['emp_code']) . '</strong>.' : '' ?></p>
            <?php else: ?>
              <p class="small text-muted">Approving <?= onb_activation_trigger() === 'approval' ? 'generates the employee ID and activates the profile (employee record, salary structure, probation, policy acknowledgements, documents and an employee login).' : 'marks the candidate ready to join; the employee ID is generated when you confirm joining.' ?></p>
              <?php if ($blockers): ?>
                <div class="small"><strong>Still open:</strong><ul class="mb-2 ps-3"><?php foreach ($blockers as $bl): ?><li><?= e($bl) ?></li><?php endforeach; ?></ul></div>
              <?php endif; ?>
              <?= $canManage ? post_button(url('onboarding', ['a' => 'final_approve']), '<i class="ri-checkbox-circle-line me-1"></i>Approve onboarding', 'btn btn-success w-100' . ($blockers ? ' disabled' : ''), 'Give final approval for ' . $o['full_name'] . '?' . (onb_activation_trigger() === 'approval' ? ' The employee ID and profile are created now.' : ''), ['id' => $o['id']]) : '' ?>
              <?php if ($canManage && $st === 'Submitted'): ?>
                <?= str_replace('<button', '<button data-prompt="What should the candidate correct? (Rejected documents are listed automatically.)"', post_button(url('onboarding', ['a' => 'request_corrections']), '<i class="ri-arrow-go-back-line me-1"></i>Return for correction', 'btn btn-outline-warning w-100 mt-2', '', ['id' => $o['id']])) ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="card card-body small text-muted"><i class="ri-information-line"></i> Required items are configured in Settings → Onboarding. Aadhaar is validated by checksum and only its last 4 digits are stored.</div>
        </div>
      </div>

    <?php elseif ($tab === 'joining'):
        $editableJ = $canManage && in_array($st, ['Ready to Join', 'Activated'], true);
        $trainings = all("SELECT id, title, start_date FROM hrm_trainings WHERE status IN ('Scheduled','Ongoing') OR start_date >= CURDATE() ORDER BY start_date");
        $enrolled = $o['employee_id'] ? array_map('intval', array_column(all('SELECT training_id FROM hrm_training_participants WHERE employee_id = ?', [$o['employee_id']]), 'training_id')) : [];
        $tasks = $o['employee_id'] ? all('SELECT title, due_date, status FROM hrm_tasks WHERE assignee_id = ? ORDER BY due_date', [$o['employee_id']]) : []; ?>
      <?php if (!in_array($st, ['Ready to Join', 'Activated', 'Joined'], true)): ?>
        <div class="card"><?php empty_state('ri-door-open-line', 'Not yet', 'Joining & induction opens after HR final approval.'); ?></div>
      <?php else: ?>
      <form method="post" action="<?= e(url('onboarding', ['a' => 'joining'])) ?>" class="grid grid-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <div class="span-2 vstack gap-3">
          <fieldset class="card form-block" <?= $editableJ ? '' : 'disabled' ?>><div class="form-block-head"><span class="fb-icon"><i class="ri-door-open-line"></i></span><div><h6>Joining &amp; induction</h6><small>Confirm the joining, plan the induction, and give system access.</small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?= form_field('joined_on', ['label' => 'Actual joining date', 'type' => 'date', 'col' => 'col-md-4'], $o['joined_on'] ?: $o['joining_date']) ?>
              <?= form_field('induction_at', ['label' => 'Induction session', 'type' => 'datetime-local', 'col' => 'col-md-4'], $o['induction_at']) ?>
              <?= form_field('buddy_id', ['label' => 'Onboarding buddy', 'type' => 'select', 'options' => employee_options(), 'col' => 'col-md-4'], $o['buddy_id']) ?>
              <?= form_field('induction_notes', ['label' => 'Induction plan / notes', 'type' => 'textarea', 'rows' => 3, 'col' => 'col-12', 'placeholder' => 'Agenda, people to meet, first-week goals…'], $o['induction_notes']) ?>
              <div class="col-12"><label class="form-label">System access &amp; assets</label>
                <div class="row g-1"><?php foreach (onb_access_items() as $i => $label): $done = $o['access_data'][$label] ?? null; ?>
                  <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="access[<?= $i ?>]" value="1" <?= $done ? 'checked' : '' ?>> <span class="form-check-label"><?= e($label) ?><?= $done ? ' <small class="text-muted">· ' . e($done['by']) . '</small>' : '' ?></span></label></div>
                <?php endforeach; ?>
                  <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" checked disabled> <span class="form-check-label">HRMS login (automatic<?= $emp ? ', active' : ' at activation' ?>)</span></label></div></div></div>
              <?php if ($trainings): ?>
                <div class="col-md-6"><label class="form-label" for="f_trainings">Initial training</label>
                  <select class="form-select" id="f_trainings" name="trainings[]" multiple size="<?= min(5, count($trainings)) ?>"><?php foreach ($trainings as $t): ?><option value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $enrolled, true) ? 'selected' : '' ?>><?= e($t['title']) ?><?= $t['start_date'] ? ' · ' . fdate($t['start_date'], 'd M') : '' ?></option><?php endforeach; ?></select>
                  <div class="form-text">Ctrl / ⌘ + click to pick several. Enrolled when joining is confirmed.</div></div>
              <?php endif; ?>
              <div class="col-md-6"><label class="form-check mt-md-4"><input class="form-check-input" type="checkbox" name="create_tasks" value="1" checked> <span class="form-check-label">Assign the onboarding tasks (<?= count(onb_task_list()) ?>) to the new employee</span></label>
                <div class="form-text"><?= e(implode(' · ', array_column(array_slice(onb_task_list(), 0, 3), 0))) ?>… (Settings → Onboarding)</div></div>
            </div></div></fieldset>
          <?php if ($editableJ): ?>
            <div class="form-actions sticky-actions"><button class="btn btn-light border" name="do" value="save"><i class="ri-save-3-line me-1"></i>Save plan</button>
              <button class="btn btn-success ms-auto" name="do" value="confirm" data-confirm="Confirm that <?= e($o['full_name']) ?> has joined?<?= $emp ? '' : ' The employee ID and profile are created now.' ?>"><i class="ri-user-follow-line me-1"></i>Confirm joining</button></div>
          <?php endif; ?>
        </div>
        <div class="vstack gap-3">
          <div class="card card-body small">
            <h6 class="fw-bold"><i class="ri-user-follow-line text-success me-1"></i>Employee profile</h6>
            <?php if ($emp): ?>
              <div>Employee ID <strong><?= e($emp['emp_code']) ?></strong> · <?= badge($emp['status']) ?></div>
              <ul class="ps-3 mt-2 mb-2"><li>Salary structure created from the offer</li><li>Probation started</li><li>Verified documents in the employee file</li><li>Policies signed during onboarding acknowledged</li><li><?= e(ROLES[normalize_role($o['login_role'])]) ?> login (same e-mail and password)</li></ul>
              <a class="btn btn-sm btn-light border" href="<?= e(url('employees', ['a' => 'view', 'id' => $emp['id']])) ?>">Open profile</a>
            <?php else: ?><p class="text-muted mb-0">Created when you confirm joining (Settings → Onboarding → activation).</p><?php endif; ?>
          </div>
          <?php if ($tasks): ?><div class="card"><div class="card-header-lite">Tasks</div><?php foreach ($tasks as $t): ?><div class="list-row"><div class="grow"><div class="title"><?= e($t['title']) ?></div><div class="sub">Due <?= fdate($t['due_date']) ?></div></div><?= badge($t['status']) ?></div><?php endforeach; ?></div><?php endif; ?>
        </div>
      </form>
      <?php endif; ?>
    <?php endif;
    page_end(['js/onboarding.js']);
    return;
}

/* ═════════════════════════ HR: templates ═════════════════════════ */

if ($a === 'template') {
    authorize('manage_onboarding');
    $t = int_input('id') ? (one("SELECT * FROM hrm_onboarding_templates WHERE id = ? AND kind IN ('offer','agreement','nda')", [int_input('id')]) ?? not_found()) : null;
    $kind = $t['kind'] ?? (isset(ONB_TEMPLATE_KINDS[input('kind')]) ? input('kind') : 'offer');
    if (is_post()) {
        $name = mb_substr(trim((string) input('name')), 0, 150);
        $body = trim((string) input('body'));
        if ($name === '' || mb_strlen($body) < 50) {
            $_SESSION['_old'] = $_POST;
            flash('danger', 'Give the template a name and some text (at least a few sentences).');
            redirect(url('onboarding', ['a' => 'template', 'id' => $t['id'] ?? null, 'kind' => $kind]));
        }
        $data = ['kind' => $kind, 'name' => $name, 'body' => $body, 'active' => !empty($_POST['active']) ? 1 : 0, 'is_default' => !empty($_POST['is_default']) ? 1 : 0, 'updated_by' => user()['id']];
        if ($data['is_default']) q('UPDATE hrm_onboarding_templates SET is_default = 0 WHERE kind = ?', [$kind]);
        if ($t) {
            // A changed agreement / NDA is a new version: candidates who have not signed yet sign the new text.
            if ($body !== $t['body']) $data['version'] = (int) $t['version'] + 1;
            update('hrm_onboarding_templates', $data, (int) $t['id']);
            $id = (int) $t['id'];
        } else {
            $id = insert('hrm_onboarding_templates', $data);
        }
        log_activity($t ? 'updated' : 'created', 'onboarding template', $id, $name);
        flash('success', 'Template saved' . (isset($data['version']) ? ' as version ' . $data['version'] : '') . '.');
        redirect(url('onboarding', ['a' => 'templates']));
    }
    page_start($t ? 'Edit template' : 'New ' . strtolower(ONB_TEMPLATE_KINDS[$kind]) . ' template', ['Onboarding' => url('onboarding'), 'Templates' => url('onboarding', ['a' => 'templates']), $t['name'] ?? 'New']);
    $ph = ['candidate_name', 'first_name', 'email', 'phone', 'address', 'designation', 'department', 'manager', 'employment_type', 'work_location', 'ctc', 'ctc_words', 'monthly_gross',
        'salary_table', 'joining_date', 'offer_expiry', 'probation_months', 'notice_period', 'offer_ref', 'offer_date', 'today', 'company_name', 'company_address', 'company_email', 'hr_name']; ?>
    <form method="post" class="grid grid-3"><?= csrf_field() ?>
      <div class="span-2 vstack gap-3">
        <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-file-text-line"></i></span><div><h6><?= e(ONB_TEMPLATE_KINDS[$kind]) ?></h6><small><?= $kind === 'offer' ? 'Rendered and fixed when an offer is approved.' : 'Signed by every new joiner during onboarding (the default active template).' ?></small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?= form_field('name', ['label' => 'Template name', 'required' => true, 'col' => 'col-md-8'], old('name', $t['name'] ?? '')) ?>
            <div class="col-md-4 d-flex flex-column justify-content-end gap-1">
              <label class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" <?= old('active', $t['active'] ?? 1) ? 'checked' : '' ?>> <span class="form-check-label">Active</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="is_default" value="1" <?= old('is_default', $t['is_default'] ?? 0) ? 'checked' : '' ?>> <span class="form-check-label">Default for <?= e(strtolower(ONB_TEMPLATE_KINDS[$kind])) ?></span></label></div>
            <div class="col-12"><label class="form-label" for="f_body">Text <span class="text-danger">*</span></label>
              <textarea class="form-control font-monospace small" id="f_body" name="body" rows="24" required><?= e(old('body', $t['body'] ?? '')) ?></textarea>
              <div class="form-text">Formatting: <code>## Heading</code>, <code>- list item</code>, <code>1. numbered</code>, <code>**bold**</code>, blank line = new paragraph.</div></div>
          </div></div></section>
        <div class="form-actions sticky-actions"><a class="btn btn-light border" href="<?= e(url('onboarding', ['a' => 'templates'])) ?>">Cancel</a><button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save template</button></div>
      </div>
      <div class="card card-body small align-self-start"><h6 class="fw-bold">Placeholders</h6><p class="text-muted">Click to insert at the cursor.</p>
        <div class="d-flex flex-wrap gap-1"><?php foreach ($ph as $p): ?><button type="button" class="tag border-0" data-insert="{{<?= $p ?>}}">{{<?= $p ?>}}</button><?php endforeach; ?></div>
        <?php if ($t): ?><p class="text-muted mt-3 mb-0">Version <?= (int) $t['version'] ?> · updated <?= time_ago($t['updated_at'] ?: $t['created_at']) ?></p><?php endif; ?>
        <p class="text-muted mt-3 mb-0"><i class="ri-scales-3-line"></i> Starter texts are a general Indian-context draft. Have them reviewed by your legal adviser.</p></div>
    </form>
    <?php
    page_end(['js/onboarding.js']);
    return;
}

if ($a === 'templates') {
    authorize('manage_onboarding');
    $rows = all('SELECT t.*, (SELECT COUNT(*) FROM hrm_onboardings o WHERE o.template_id = t.id) used FROM hrm_onboarding_templates t WHERE t.kind IN ("offer","agreement","nda") ORDER BY FIELD(t.kind,"offer","agreement","nda"), t.is_default DESC, t.name');
    page_start('Onboarding templates', ['Onboarding' => url('onboarding'), 'Templates'],
        '<div class="dropdown"><button class="btn btn-primary" data-bs-toggle="dropdown"><i class="ri-add-line me-1"></i>New template</button><div class="dropdown-menu dropdown-menu-end">'
        . implode('', array_map(fn($k, $l) => '<a class="dropdown-item" href="' . e(url('onboarding', ['a' => 'template', 'kind' => $k])) . '">' . e($l) . '</a>', array_keys(ONB_TEMPLATE_KINDS), ONB_TEMPLATE_KINDS)) . '</div></div>'); ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
      <thead><tr><th>Template</th><th>Type</th><th>Version</th><th>Status</th><th>Used</th><th>Updated</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="row-link" data-href="<?= e(url('onboarding', ['a' => 'template', 'id' => $r['id']])) ?>"><td class="fw-semibold"><?= e($r['name']) ?> <?= $r['is_default'] ? '<span class="pill pill-info">Default</span>' : '' ?></td>
          <td><?= e(ONB_TEMPLATE_KINDS[$r['kind']] ?? $r['kind']) ?></td><td><?= (int) $r['version'] ?></td><td><?= $r['active'] ? badge('Active') : badge('Inactive') ?></td>
          <td><?= $r['kind'] === 'offer' ? (int) $r['used'] . ' offer(s)' : '—' ?></td><td class="small text-muted"><?= time_ago($r['updated_at'] ?: $r['created_at']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(url('onboarding', ['a' => 'template', 'id' => $r['id']])) ?>">Edit</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No templates.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <p class="small text-muted mt-2"><i class="ri-information-line"></i> The default active <em>employment agreement</em> and <em>NDA</em> are signed by every new joiner, together with published HR policies that need acknowledgement.</p>
    <?php
    page_end();
    return;
}

if ($a === 'hires') { require APP_DIR . '/modules/partials/onb_hires.php'; return; }

/* ═════════════════════════ HR: case list ═════════════════════════ */

$filter = (string) input('status');
$where = '1=1';
$params = [];
if ($filter === 'action') $where = "(o.status IN ('Submitted','Changes Requested','Ready to Join','Pending Approval') OR (o.status = 'Activated' AND o.joining_date <= CURDATE()))";
elseif ($filter === 'closed') $where = "o.status IN ('Joined','Declined','Withdrawn')";
elseif (in_array($filter, ONB_STATUSES, true)) { $where = 'o.status = ?'; $params[] = $filter; }
else $where = "o.status NOT IN ('Joined','Declined','Withdrawn')";
if (($q = trim((string) input('q'))) !== '') { $where .= ' AND (o.full_name LIKE ? OR o.email LIKE ? OR o.offer_ref LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$rows = all("SELECT o.*, g.title des, d.name dept FROM hrm_onboardings o LEFT JOIN hrm_designations g ON g.id = o.designation_id LEFT JOIN hrm_departments d ON d.id = o.department_id
             WHERE $where ORDER BY FIELD(o.status,'Pending Approval','Submitted','Changes Requested','Ready to Join','Activated','Approved','Draft','Correction Requested','Accepted','Offer Sent','Expired','Joined','Declined','Withdrawn'), o.joining_date LIMIT 200", $params);
$counts = array_column(all('SELECT status, COUNT(*) n FROM hrm_onboardings GROUP BY status'), 'n', 'status');
$cnt = fn(array $s) => array_sum(array_intersect_key($counts, array_flip($s)));
$joiningSoon = (int) val("SELECT COUNT(*) FROM hrm_onboardings WHERE status IN ('Accepted','Submitted','Correction Requested','Ready to Join','Activated') AND joining_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$ready = $canManage ? (int) val("SELECT COUNT(*) FROM hrm_candidates c WHERE c.stage = 'Selected' AND NOT EXISTS (SELECT 1 FROM hrm_onboardings o WHERE o.candidate_id = c.id AND o.status NOT IN ('Declined','Withdrawn'))") : 0;
page_start('Onboarding', ['Onboarding'], ($canManage ? '<a class="btn btn-light border" href="' . e(url('onboarding', ['a' => 'hires'])) . '"><i class="ri-dashboard-3-line me-1"></i>New hires dashboard</a><a class="btn btn-light border" href="' . e(url('onboarding', ['a' => 'templates'])) . '"><i class="ri-file-copy-2-line me-1"></i>Templates</a>'
    . '<a class="btn btn-primary" href="' . e(url('onboarding', ['a' => 'create'])) . '"><i class="ri-user-add-line me-1"></i>Start onboarding</a>' : ''));
?>
<?php if ($ready): ?>
  <a class="action-banner mb-3" href="<?= e(url('onboarding', ['a' => 'create'])) ?>"><i class="ri-user-star-line"></i><div class="grow"><strong><?= $ready ?> selected candidate<?= $ready > 1 ? 's are' : ' is' ?> waiting for an offer</strong><div class="small">Generate the offer letter from a template and start their onboarding.</div></div><span class="btn btn-sm btn-primary">Start</span></a>
<?php endif; ?>
<div class="grid grid-4 mb-3">
  <?= stat_card('Offers in progress', $cnt(['Draft', 'Pending Approval', 'Changes Requested', 'Approved']), 'ri-file-text-line', 'primary', $cnt(['Pending Approval']) . ' awaiting approval', url('onboarding', ['status' => 'Pending Approval'])) ?>
  <?= stat_card('With candidates', $cnt(['Offer Sent', 'Accepted', 'Correction Requested']), 'ri-mail-send-line', 'info', $cnt(['Offer Sent']) . ' to accept · ' . $cnt(['Accepted', 'Correction Requested']) . ' filling in', url('onboarding', ['status' => 'Accepted'])) ?>
  <?= stat_card('To verify', $cnt(['Submitted']), 'ri-shield-check-line', 'warning', 'Documents & compliance', url('onboarding', ['status' => 'Submitted'])) ?>
  <?= stat_card('Joining in 30 days', $joiningSoon, 'ri-calendar-check-line', 'success', $cnt(['Joined']) . ' joined so far', url('onboarding', ['status' => 'closed'])) ?>
</div>
<div class="card">
  <nav class="list-tabs" aria-label="Onboarding status">
    <?php foreach (['' => 'Open', 'action' => 'Needs action', 'Pending Approval' => 'Approval', 'Offer Sent' => 'Offer sent', 'Accepted' => 'Filling in', 'Submitted' => 'To verify', 'Activated' => 'Activated', 'closed' => 'Closed'] as $k => $l): ?>
      <a class="<?= $filter === (string) $k ? 'active' : '' ?>" href="<?= e(url('onboarding', array_filter(['status' => $k, 'q' => $q]))) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="toolbar" method="get" action="<?= e(url('onboarding')) ?>"><?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, e-mail or offer ref…"></div>
    <span class="ms-auto small text-muted result-count"><?= count($rows) ?> case(s)</span></form>
  <?php if (!$rows): empty_state('ri-user-received-2-line', 'No onboarding cases here', $canManage ? 'Start onboarding for a candidate in the Selected stage.' : 'Offers waiting for your approval appear here.', $canManage ? '<a class="btn btn-primary" href="' . e(url('onboarding', ['a' => 'create'])) . '">Start onboarding</a>' : ''); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Candidate</th><th>Position</th><th>Joining</th><th>CTC</th><th>Status</th><th>Form</th><th>Updated</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $r['profile_data'] = json_decode((string) $r['profile'], true) ?: []; $pc = $r['accepted_at'] ? onb_percent($r + ['compliance_data' => []]) : null; ?>
      <tr class="row-link" data-href="<?= e(url('onboarding', ['a' => 'view', 'id' => $r['id']])) ?>">
        <td><a class="person" href="<?= e(url('onboarding', ['a' => 'view', 'id' => $r['id']])) ?>"><?= avatar($r['full_name'], null, 'sm') ?><span class="meta"><strong class="text-body"><?= e($r['full_name']) ?></strong><small><?= e($r['offer_ref']) ?> · <?= e($r['email']) ?></small></span></a></td>
        <td><?= e($r['des'] ?: '—') ?><div class="small text-muted"><?= e($r['dept'] ?: '') ?></div></td>
        <td class="text-nowrap"><?= fdate($r['joining_date']) ?></td><td class="text-nowrap"><?= money($r['ctc_annual']) ?></td><td><?= badge($r['status']) ?></td>
        <td style="min-width:110px"><?php if ($pc !== null): ?><div class="progress" style="height:6px" title="<?= $pc ?>%"><div class="progress-bar <?= $pc === 100 ? 'bg-success' : '' ?>" style="width:<?= $pc ?>%"></div></div><small class="text-muted"><?= $pc ?>%</small><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
        <td class="small text-muted text-nowrap"><?= time_ago($r['updated_at'] ?: $r['created_at']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php
page_end();
