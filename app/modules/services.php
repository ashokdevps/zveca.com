<?php
/**
 * Client portal: services (see app/lib/engagements.php).
 *   index                 my requests / projects, documents to sign, new request
 *   new (?service=)       choose HR or IT, then the dynamic form · edit (id) for drafts / information requests
 *   save (POST)           save draft or submit
 *   view (id)             tracker, approvals, documents, billing, delivery, discussion
 *   comment, escalate, deliverable, feedback, cancel, decline (POST)
 *   documents             Document Centre: agreements, SOPs, proposals, deliverables, invoices, signature evidence
 *   doc (id)              one document with its signature certificate · docfile (id) .docx download when permitted · respond (POST) decline / request changes
 *   documents?tab=offers  offer letters of the company's hires (HR + recruitment entitlement), read-only
 *   sign (id) / sign_otp  signing ceremony: authorised signatory + e-mail OTP + drawn signature
 * Everything is scoped to the signed-in client's company and the login's service scope.
 */

if (!is_client()) redirect(url('engagements'));
if (!plan_allows('manage_engagements')) redirect(url());
$cid = my_client_id();
$me = user();
$a = $_GET['a'] ?? 'index';
$services = array_filter(ENG_SERVICES, fn($k) => client_scope_allows($k), ARRAY_FILTER_USE_KEY);

/** A client document of this company within the login's scope. */
function svc_doc(int $id): array
{
    // Same permission model as everywhere else (company, service scope, shared status, revocation, offer entitlement).
    $d = doc_find($id);
    if (!$d || !doc_can($d, 'view')) not_found();
    return $d;
}

/** The signer's verified session (OTP checked within the last 15 minutes by this login), or null. */
function svc_signer_session(): ?array
{
    $s = $_SESSION['csigner'] ?? null;
    return $s && (int) $s['login'] === (int) user()['id'] && $s['until'] > time() ? $s : null;
}

function svc_back(array $e, string $type, string $msg, string $hash = ''): never
{
    flash($type, $msg);
    redirect(url('services', ['a' => 'view', 'id' => $e['id']]) . $hash);
}

/* ───────────── Save / submit a request ───────────── */
if ($a === 'save' && is_post()) {
    $id = int_input('id');
    $e = $id ? eng_access($id) : null;
    $service = $e['service'] ?? (string) input('service');
    if (!isset($services[$service])) not_found();
    if ($e && !in_array($e['status'], ['Draft', 'Info Requested'], true)) svc_back($e, 'warning', 'This request is with our team and can no longer be edited. Add a comment instead.');
    $submit = input('do') === 'submit';
    [$answers, $errors] = eng_collect_form($service, $e['form_data'] ?? [], $submit);
    $title = mb_substr((string) ($answers['position_title'] ?? $answers['project_title'] ?? ''), 0, 190) ?: null;
    if ($submit && $errors) {
        if ($e) eng_save((int) $e['id'], ['form' => $answers, 'title' => $title]);
        else $id = insert('hrm_engagements', ['client_id' => $cid, 'service' => $service, 'title' => $title, 'status' => 'Draft', 'form' => json_encode($answers, JSON_UNESCAPED_UNICODE), 'submitted_by' => $me['id']]);
        flash('danger', 'Please complete the form:<br>' . implode('<br>', array_map('e', $errors)));
        redirect(url('services', ['a' => 'edit', 'id' => $id]));
    }
    if (!$e) {
        $id = insert('hrm_engagements', ['client_id' => $cid, 'service' => $service, 'title' => $title, 'status' => 'Draft', 'form' => json_encode($answers, JSON_UNESCAPED_UNICODE), 'submitted_by' => $me['id']]);
        $e = eng_find($id);
    } else {
        eng_save((int) $e['id'], ['form' => $answers, 'title' => $title]);
    }
    if (!$submit) {
        eng_log($cid, (int) $e['id'], 'draft_saved', null, 'client');
        flash($errors ? 'warning' : 'success', 'Draft saved.' . ($errors ? ' Note: ' . e(implode(' ', $errors)) : ''));
        redirect(url('services', ['a' => 'edit', 'id' => $e['id']]));
    }
    $again = $e['status'] === 'Info Requested';
    $ref = $e['ref'] ?: eng_next_ref($service);
    eng_save((int) $e['id'], ['status' => 'Submitted', 'ref' => $ref, 'submitted_at' => $e['submitted_at'] ?: date('Y-m-d H:i:s'), 'submitted_by' => $e['submitted_by'] ?: $me['id']]);
    $e = eng_find((int) $e['id']);
    eng_log($cid, (int) $e['id'], $again ? 'info_provided' : 'submitted', eng_title($e), 'client');
    eng_notify_staff($e, $again ? 'information provided' : 'new ' . strtolower(ENG_SERVICES[$service]['form']), "{$me['username']} of {$e['client_name']} " . ($again ? 'updated the request with the information you asked for' : 'submitted a new ' . strtolower(ENG_SERVICES[$service]['form'])) . ': ' . eng_title($e) . '.');
    eng_notify_client($e, ($again ? 'Updated' : 'Received') . ": {$ref} " . eng_title($e), "Thank you. We have received your " . strtolower(ENG_SERVICES[$service]['form']) . " ($ref). Our team will review it and send you a proposal with the agreements to sign. Nothing is activated until you approve it.\n\nTrack it: " . site_origin() . url('services', ['a' => 'view', 'id' => $e['id']]));
    svc_back($e, 'success', '<strong>Submitted.</strong> Reference ' . e($ref) . '. We\'ll review it and send you a proposal.');
}

/* ───────────── Actions on a request ───────────── */
if (in_array($a, ['comment', 'escalate', 'deliverable', 'feedback', 'cancel', 'decline'], true) && is_post()) {
    $e = eng_access(int_input('id'));
    $body = mb_substr(trim((string) input('body', input('note', ''))), 0, 4000);
    if ($a === 'comment') {
        if ($body === '') svc_back($e, 'danger', 'Write a message first.', '#discussion');
        $file = null;
        try { $file = store_upload('attachment', 'clientdocs', 10); } catch (RuntimeException $ex) { svc_back($e, 'danger', e($ex->getMessage()), '#discussion'); }
        insert('hrm_engagement_messages', ['engagement_id' => $e['id'], 'login_id' => $me['id'], 'kind' => 'comment', 'body' => $body, 'attachment' => $file]);
        eng_log($cid, (int) $e['id'], 'comment', mb_strimwidth($body, 0, 120, '…'), 'client');
        eng_notify_staff($e, 'new comment from the client', "{$me['username']} wrote:\n\n$body");
        svc_back($e, 'success', 'Message sent.', '#discussion');
    }
    if ($a === 'escalate') {
        if ($body === '') svc_back($e, 'danger', 'Describe the issue you want to escalate.', '#discussion');
        $tno = next_ticket_no();
        $tid = insert('hrm_tickets', ['ticket_no' => $tno, 'subject' => mb_substr("Escalation: {$e['ref']} " . eng_title($e), 0, 200), 'category' => 'Escalation', 'priority' => 'High', 'status' => 'Open',
            'channel' => 'Portal', 'requester_id' => $me['id'], 'sla_due_at' => date('Y-m-d H:i:s', time() + sla_hours('High') * 3600), 'last_activity_at' => date('Y-m-d H:i:s'), 'engagement_id' => $e['id']]);
        ticket_message($tid, $body);
        insert('hrm_engagement_messages', ['engagement_id' => $e['id'], 'login_id' => $me['id'], 'kind' => 'escalation', 'body' => $body, 'ticket_id' => $tid]);
        eng_log($cid, (int) $e['id'], 'escalated', $tno, 'client');
        eng_notify_staff($e, 'ESCALATION ' . $tno, "{$me['username']} escalated this request (ticket $tno, high priority):\n\n$body", true);
        svc_back($e, 'success', 'Escalation raised as ticket <strong>' . e($tno) . '</strong>. A senior team member will respond within ' . sla_hours('High') . ' hours.', '#discussion');
    }
    if ($a === 'deliverable') {
        $d = one("SELECT * FROM hrm_client_documents WHERE id = ? AND engagement_id = ? AND kind = 'deliverable' AND status IN ('Shared','Changes Requested')", [int_input('doc'), $e['id']]) ?? not_found();
        $accept = input('decision') === 'accept';
        if (!$accept && $body === '') svc_back($e, 'danger', 'Tell us what needs to change.', '#delivery');
        update('hrm_client_documents', ['status' => $accept ? 'Accepted' : 'Changes Requested', 'review_note' => $body ?: null, 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
        eng_log($cid, (int) $e['id'], $accept ? 'deliverable_accepted' : 'deliverable_changes', $d['title'] . ($body ? ": $body" : ''), 'client');
        eng_notify_staff($e, $accept ? 'deliverable accepted' : 'changes requested on a deliverable', "{$me['username']} " . ($accept ? 'accepted' : 'requested changes to') . " \"{$d['title']}\"" . ($body ? ":\n\n$body" : '.'));
        svc_back($e, 'success', $accept ? 'Deliverable accepted. Thank you!' : 'Your feedback was sent to the team.', '#delivery');
    }
    if ($a === 'feedback') {
        $r = int_input('rating');
        if ($r < 1 || $r > 5 || !in_array($e['status'], ['Delivered', 'Closed'], true)) svc_back($e, 'danger', 'Choose a rating from 1 to 5.');
        eng_save((int) $e['id'], ['client_rating' => $r, 'client_feedback' => $body ?: null]);
        eng_log($cid, (int) $e['id'], 'feedback', "$r/5" . ($body ? " · $body" : ''), 'client');
        eng_notify_staff($e, "client feedback $r/5", $body ?: 'No comment.');
        svc_back($e, 'success', 'Thank you for your feedback!');
    }
    if ($a === 'cancel') {
        if (!in_array($e['status'], ['Draft', 'Submitted', 'Info Requested'], true)) svc_back($e, 'warning', 'This request is already in progress. Please write to us to cancel it.');
        eng_save((int) $e['id'], ['status' => 'Cancelled', 'closed_at' => date('Y-m-d H:i:s'), 'closure_type' => 'Cancelled', 'closure_note' => $body ?: 'Cancelled by the client']);
        eng_log($cid, (int) $e['id'], 'cancelled', $body ?: null, 'client');
        if ($e['status'] !== 'Draft') eng_notify_staff($e, 'cancelled by the client', $body ?: 'No reason given.');
        flash('success', 'Request cancelled.');
        redirect(url('services'));
    }
    if ($a === 'decline') {
        if ($e['status'] !== 'Proposal Sent') svc_back($e, 'warning', 'There is no open proposal to decline.');
        if ($body === '') svc_back($e, 'danger', 'Please tell us why, so we can revise the proposal.');
        insert('hrm_engagement_messages', ['engagement_id' => $e['id'], 'login_id' => $me['id'], 'kind' => 'comment', 'body' => "Proposal not accepted: $body"]);
        eng_save((int) $e['id'], ['status' => 'Submitted']);
        eng_log($cid, (int) $e['id'], 'declined', 'Proposal: ' . $body, 'client');
        eng_notify_staff($e, 'proposal not accepted', "{$me['username']} did not accept the proposal:\n\n$body\n\nRevise the commercials / SOP and send it again.");
        svc_back($e, 'success', 'Thanks. We\'ll revise the proposal and get back to you.');
    }
}

/* ───────────── Signing ceremony ───────────── */
if ($a === 'sign_otp' && is_post()) {
    $d = svc_doc(int_input('id'));
    if (!doc_can($d, 'sign')) { flash('warning', 'This document cannot be signed now: it is already signed, expired, withdrawn, changed or addressed to another signatory.'); redirect(url('services', ['a' => 'doc', 'id' => $d['id']])); }
    $email = strtolower((string) $me['email']);
    if ($wait = login_lockout_minutes($email, 'csign_send')) { flash('danger', "Too many codes requested. Try again in $wait minute(s)."); redirect(url('services', ['a' => 'sign', 'id' => $d['id']])); }
    record_login_attempt($email, false, 'csign_send');
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['csign'][$d['id']] = ['hash' => hash('sha256', $d['id'] . '|' . $d['content_hash'] . '|' . $otp), 'expires' => time() + 600, 'tries' => 0];
    send_mail($me['email'], "$otp is your signing code for {$d['title']}", "Hello {$me['username']},\n\nYour one-time code to sign \"{$d['title']}\" (version {$d['version']}) is: $otp\n\nIt is valid for 10 minutes. If you are not signing this document, do not share the code and let us know.\n\n— " . setting('company_name'));
    flash('info', 'We e-mailed a 6-digit signing code to ' . e(mask_email($me['email'])) . '.');
    redirect(url('services', ['a' => 'sign', 'id' => $d['id']]));
}
if ($a === 'sign' && is_post()) {
    $d = svc_doc(int_input('id'));
    $back = url('services', ['a' => 'sign', 'id' => $d['id']]);
    if (!doc_can($d, 'sign')) { flash('warning', 'This document cannot be signed now: it is already signed, expired, withdrawn, changed or addressed to another signatory.'); redirect(url('services', ['a' => 'doc', 'id' => $d['id']])); }
    $s = $_SESSION['csign'][$d['id']] ?? null;
    $otp = preg_replace('/\D+/', '', (string) input('otp'));
    $email = strtolower((string) $me['email']);
    $session = svc_signer_session();
    try {
        if (!$session) {
            if (!$s || $s['expires'] < time()) throw new RuntimeException('Request a signing code first (it is valid for 10 minutes).');
            if (++$_SESSION['csign'][$d['id']]['tries'] > 5 || login_lockout_minutes($email, 'csign_otp')) { unset($_SESSION['csign'][$d['id']]); throw new RuntimeException('Too many wrong codes. Request a new code.'); }
            // The code is bound to this exact version of the text: if the document changed meanwhile, it no longer matches.
            if (!hash_equals($s['hash'], hash('sha256', $d['id'] . '|' . $d['content_hash'] . '|' . $otp))) { record_login_attempt($email, false, 'csign_otp'); throw new RuntimeException('The signing code is not correct.'); }
        }
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string) input('signer_name'))), 0, 150);
        $desig = mb_substr(trim((string) input('signer_designation')), 0, 120);
        if (mb_strlen($name) < 3) throw new RuntimeException('Type your full name.');
        if ($desig === '') throw new RuntimeException('Enter your designation.');
        if (empty($_POST['sign_consent'])) throw new RuntimeException('Tick the box to confirm you are signing on behalf of the company.');
        if (!hash_equals((string) $d['content_hash'], hash('sha256', (string) $d['content']))) throw new RuntimeException('This document failed its integrity check. Please contact us.');
        $sig = onb_store_signature((string) input('signature'), (int) $d['id'], $d['kind'], 'clientdocs', 'csig');
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
        redirect($back);
    }
    if (!$session) {
        record_login_attempt($email, true, 'csign_otp');
        // Verified signer session: the same signatory can sign the other documents of this sitting for 15 minutes without a new code.
        $_SESSION['csigner'] = ['login' => (int) $me['id'], 'at' => date('Y-m-d H:i:s'), 'until' => time() + 900];
        $session = $_SESSION['csigner'];
    }
    unset($_SESSION['csign'][$d['id']]);
    db()->beginTransaction();
    insert('hrm_client_signatures', ['document_id' => $d['id'], 'party' => 'client', 'login_id' => $me['id'], 'signer_name' => $name, 'signer_designation' => $desig, 'signer_email' => $me['email'],
        'method' => 'otp_draw', 'otp_verified_at' => $session['at'], 'signature_file' => $sig, 'content_hash' => $d['content_hash'], 'ip' => client_ip(),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'signed_at' => date('Y-m-d H:i:s')]);
    doc_after_party_sign($d); // Signed, or Partially Signed while a company countersignature is outstanding
    doc_event((int) $d['id'], 'signed', "$name ($desig) · e-mail OTP + drawn signature · SHA-256 " . substr((string) $d['content_hash'], 0, 16) . '…', 'client');
    if ($me['designation'] !== $desig) q('UPDATE it_login SET designation = ? WHERE id = ?', [$desig, $me['id']]);
    record_consent((int) $me['id'], $cid, 'esign:' . $d['kind'] . ':v' . $d['version'], 'I confirm I am authorised to sign "' . $d['title'] . '" on behalf of ' . user()['client']['name'] . ' and that this electronic signature is binding on the company.');
    db()->commit();
    eng_log($cid, $d['engagement_id'] ? (int) $d['engagement_id'] : null, 'document_signed', $d['title'] . ' v' . $d['version'] . " by $name ($desig)", 'client');
    // Proposal accepted → issue the advance invoice (once).
    if ($d['kind'] === 'proposal' && $d['engagement_id']) {
        $e = eng_find((int) $d['engagement_id']);
        if ((float) $e['advance_amount'] > 0 && !$e['payment_waived'] && !$e['advance_invoice_id']) {
            $inv = eng_create_invoice($e, 'Advance for ' . $e['ref'] . ' — ' . eng_title($e), (float) $e['advance_amount']);
            eng_save((int) $e['id'], ['advance_invoice_id' => $inv]);
        }
    }
    if ($d['engagement_id']) eng_refresh_status((int) $d['engagement_id']);
    else eng_refresh_client($cid);
    $e = $d['engagement_id'] ? eng_find((int) $d['engagement_id']) : null;
    if ($e) eng_notify_staff($e, 'document signed', "$name ($desig) signed \"{$d['title']}\" (v{$d['version']}).");
    else foreach (all('SELECT id FROM hrm_engagements WHERE client_id = ? AND status IN ' . sql_list(ENG_PROPOSAL_STAGE), [$cid]) as $r) { eng_notify_staff(eng_find((int) $r['id']), 'document signed', "$name ($desig) signed \"{$d['title']}\" (v{$d['version']})."); break; }
    $next = one("SELECT id FROM hrm_client_documents WHERE client_id = ? AND status = 'Awaiting Signature' AND id <> ? ORDER BY id LIMIT 1", [$cid, $d['id']]);
    flash('success', '<strong>Signed.</strong> ' . e($d['title']) . ' is signed and recorded.' . ($next ? ' Next document to sign is open below.' : ''));
    redirect($next && eng_doc_in_scope(svc_doc((int) $next['id'])) ? url('services', ['a' => 'sign', 'id' => $next['id']]) : ($e ? url('services', ['a' => 'view', 'id' => $e['id']]) : url('services', ['a' => 'documents'])));
}

if ($a === 'sign') {
    $d = svc_doc(int_input('id'));
    if (!in_array($d['status'], DOC_OPEN_SIGN, true) || !doc_open($d) || val("SELECT 1 FROM hrm_client_signatures WHERE document_id = ? AND party = 'client'", [$d['id']])) redirect(url('services', ['a' => 'doc', 'id' => $d['id']]));
    doc_mark_viewed($d);
    $s = $_SESSION['csign'][$d['id']] ?? null;
    $session = svc_signer_session();
    $codeSent = $session || ($s && $s['expires'] > time());
    page_start('Sign: ' . $d['title'], ['My Services' => url('services'), 'Document Centre' => url('services', ['a' => 'documents']), 'Sign']);
    $prev = $d['supersedes_id'] ? one('SELECT id, version, status FROM hrm_client_documents WHERE id = ?', [$d['supersedes_id']]) : null; ?>
    <?php if ($prev): ?><div class="alert alert-info small"><i class="ri-git-branch-line me-1"></i>This is <strong>version <?= (int) $d['version'] ?></strong>. Version <?= (int) $prev['version'] ?> (<?= e(strtolower($prev['status'])) ?>) is kept unchanged in your <a href="<?= e(url('services', ['a' => 'doc', 'id' => $prev['id']])) ?>">Document Centre</a>.</div><?php endif; ?>
    <div class="grid grid-3">
      <div class="span-2"><div class="card payslip m-0"><div class="card-body p-4 p-md-5"><?= eng_doc_letter($d) ?></div></div></div>
      <div class="vstack gap-3">
        <?php if (!doc_can($d, 'sign')): ?>
          <div class="card card-body"><h6 class="fw-bold"><i class="ri-lock-line me-1"></i>Signatory needed</h6><p class="small text-muted mb-0">Only an authorised signatory of <?= e($me['client']['name']) ?><?= $d['recipient_email'] ? ' to whom it was sent (' . e(mask_email($d['recipient_email'])) . ')' : '' ?> can sign. Ask your signatory to sign in, or ask us to add you as one.</p></div>
        <?php else: ?>
          <div class="card card-body eng-sign-card">
            <h6 class="fw-bold"><i class="ri-quill-pen-line text-primary me-1"></i>Sign this document</h6>
            <ol class="small text-muted ps-3"><li>Read the document.</li><li>Get a one-time signing code by e-mail.</li><li>Enter the code, sign and confirm.</li></ol>
            <?php if (!$codeSent): ?>
              <form method="post" action="<?= e(url('services', ['a' => 'sign_otp', 'id' => $d['id']])) ?>"><?= csrf_field() ?><button class="btn btn-primary w-100"><i class="ri-mail-send-line me-1"></i>E-mail me a signing code</button></form>
            <?php else: ?>
              <form method="post" action="<?= e(url('services', ['a' => 'sign', 'id' => $d['id']])) ?>" data-sign-form class="vstack gap-2"><?= csrf_field() ?>
                <?php if ($session): ?><div class="alert alert-success small py-2 mb-0"><i class="ri-shield-check-line me-1"></i>Identity verified by e-mail code at <?= fdate($session['at'], 'h:i A') ?>, valid for this signing session until <?= date('h:i A', $session['until']) ?>.</div>
                <?php else: ?><div><label class="form-label small mb-1" for="otp">Signing code (sent to <?= e(mask_email($me['email'])) ?>)</label><input class="form-control otp-input" id="otp" name="otp" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"></div><?php endif; ?>
                <div class="sig-pad" data-sig><div class="sig-head"><span class="small fw-semibold">Signature</span><div class="btn-group btn-group-sm"><button type="button" class="btn btn-light border active" data-sig-mode="draw">Draw</button><button type="button" class="btn btn-light border" data-sig-mode="type">Type</button></div></div>
                  <canvas aria-label="Signature box"></canvas><input type="text" class="form-control sig-type d-none" placeholder="Type your name" maxlength="60"><div class="sig-foot"><span class="small text-muted" data-sig-hint>Sign inside the box</span><button type="button" class="btn btn-sm btn-link p-0" data-sig-clear>Clear</button></div>
                  <input type="hidden" name="signature" data-sig-out></div>
                <div><label class="form-label small mb-1" for="sn">Full name</label><input class="form-control" id="sn" name="signed_name" data-mirror="signer_name" value="<?= e($me['username'] && !str_contains($me['username'], '@') ? $me['username'] : ($me['client']['contact_person'] ?? '')) ?>" required><input type="hidden" name="signer_name"></div>
                <div><label class="form-label small mb-1" for="sd">Designation</label><input class="form-control" id="sd" name="signer_designation" value="<?= e($me['designation'] ?? '') ?>" required></div>
                <label class="form-check small"><input class="form-check-input" type="checkbox" name="sign_consent" value="1" required> <span class="form-check-label">I am authorised to sign this document on behalf of <strong><?= e($me['client']['name']) ?></strong>, and I agree that this electronic signature binds the company (IT Act, 2000, s.10A).</span></label>
                <button class="btn btn-success" data-sig-submit><i class="ri-check-double-line me-1"></i>Sign document</button>
              </form>
              <form method="post" action="<?= e(url('services', ['a' => 'sign_otp', 'id' => $d['id']])) ?>" class="mt-1"><?= csrf_field() ?><button class="btn btn-link btn-sm px-0">Send a new code</button></form>
            <?php endif; ?>
          </div>
          <div class="card card-body small text-muted"><i class="ri-shield-check-line"></i> We record your name, designation, verified e-mail, the time, IP address, device and a SHA-256 fingerprint of this exact text. Signed versions are never changed; if the text changes later, you are asked to sign the new version.</div>
        <?php endif; ?>
      </div>
    </div>
    <script>document.querySelectorAll('[data-mirror]').forEach(function(i){var t=i.form.querySelector('[name="'+i.dataset.mirror+'"]');var s=function(){t.value=i.value;};i.addEventListener('input',s);s();});</script>
    <?php
    page_end(['js/onboarding.js']);
    return;
}

/* ───────────── Document Centre ───────────── */
if ($a === 'respond' && is_post()) {
    $d = svc_doc(int_input('id'));
    if (!doc_can($d, 'respond')) authorize('__never__');
    $note = trim((string) input('note'));
    $kind = input('kind') === 'decline' ? 'decline' : 'changes';
    if (mb_strlen($note) < 5) { flash('danger', 'Please tell us what you would like changed (or why you are declining).'); redirect(url('services', ['a' => 'doc', 'id' => $d['id']])); }
    doc_respond($d, $kind, $note);
    flash('success', $kind === 'decline' ? 'You declined this document. We have been notified.' : 'Change request sent. You will receive a revised version to review.');
    redirect(url('services', ['a' => 'documents']));
}

if ($a === 'docfile') {
    $d = svc_doc(int_input('id'));
    if (!doc_can($d, 'download') || $d['content'] === null) authorize('__never__');
    doc_event((int) $d['id'], 'downloaded', '.docx of version ' . $d['version'], 'client');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . (trim(preg_replace('/[^A-Za-z0-9]+/', '-', $d['title']), '-') ?: 'document') . '-v' . (int) $d['version'] . '.docx"');
    header('Cache-Control: private, no-store');
    echo html_to_docx((string) $d['content'], '');
    exit;
}

if ($a === 'doc') {
    $d = svc_doc(int_input('id'));
    doc_mark_viewed($d);
    $isOffer = $d['kind'] === 'offer_letter';
    $legacy = isset(ENG_DOC_KINDS[$d['kind']]); // proposal-pack documents always had Print / Save PDF
    $canDl = doc_can($d, 'download');
    $btns = (doc_can($d, 'sign') ? '<a class="btn btn-success" href="' . e(url('services', ['a' => 'sign', 'id' => $d['id']])) . '"><i class="ri-quill-pen-line me-1"></i>Sign</a>' : '')
        . ($canDl && $d['content'] !== null && !$legacy ? '<a class="btn btn-light border" href="' . e(url('services', ['a' => 'docfile', 'id' => $d['id']])) . '"><i class="ri-download-2-line me-1"></i>Download (.docx)</a>' : '')
        . ($canDl || $legacy ? '<button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>' : '');
    page_start($d['title'], ['My Services' => url('services'), 'Document Centre' => url('services', ['a' => 'documents'] + ($isOffer ? ['tab' => 'offers'] : [])), 'v' . $d['version']], $btns);
    if ($d['superseded_by']) echo '<div class="alert alert-warning small d-print-none"><i class="ri-git-branch-line me-1"></i>A newer version of this document exists. <a href="' . e(url('services', ['a' => 'doc', 'id' => $d['superseded_by']])) . '">Open the latest version</a>.</div>';
    if ($isOffer) echo '<div class="alert alert-info small d-print-none"><i class="ri-eye-line me-1"></i>Read-only copy of the offer letter as it was sent to the candidate (revision ' . (int) $d['version'] . ').</div>';
    if (in_array($d['status'], DOC_OPEN_SIGN, true) && $d['expires_at']) echo '<div class="small text-muted mb-2 d-print-none"><i class="ri-time-line me-1"></i>Signing request valid until ' . fdate($d['expires_at'], 'd M Y, h:i A') . '</div>';
    if (doc_can($d, 'respond')): ?>
      <div class="card card-body mb-3 d-print-none d-flex flex-row flex-wrap gap-2 align-items-center"><span class="small text-muted me-auto">Not ready to sign?</span>
        <button type="button" class="btn btn-sm btn-outline-warning" data-post="<?= e(url('services', ['a' => 'respond'])) ?>" data-prompt="What should be changed?" data-fields='<?= e(json_encode(['id' => $d['id'], 'kind' => 'changes'])) ?>'><i class="ri-edit-box-line me-1"></i>Request changes</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-post="<?= e(url('services', ['a' => 'respond'])) ?>" data-prompt="Why are you declining this document?" data-fields='<?= e(json_encode(['id' => $d['id'], 'kind' => 'decline'])) ?>'><i class="ri-close-circle-line me-1"></i>Decline</button></div>
    <?php endif;
    echo '<div class="card payslip' . ($isOffer && !$canDl ? ' no-print-copy' : '') . '"><div class="card-body p-4 p-md-5">' . ($isOffer ? $d['content'] : eng_doc_letter($d, eng_certificate($d))) . '</div></div>';
    page_end();
    return;
}

if ($a === 'documents') {
    $docs = array_values(array_filter(all('SELECT d.*, e.service eng_service, e.ref, e.title eng_title FROM hrm_client_documents d LEFT JOIN hrm_engagements e ON e.id = d.engagement_id
                              WHERE d.client_id = ? AND d.status <> "Void" ORDER BY d.engagement_id IS NOT NULL, d.kind, d.version DESC', [$cid]), fn($d) => doc_can($d, 'view')));
    $invoices = can('client_invoices') ? all("SELECT i.*, e.ref FROM hrm_invoices i LEFT JOIN hrm_engagements e ON e.id = i.engagement_id WHERE i.client_id = ? AND i.status <> 'Cancelled' ORDER BY i.issue_date DESC", [$cid]) : [];
    $agreements = array_values(array_filter($docs, fn($d) => $d['kind'] !== 'offer_letter'));
    $company = array_filter($agreements, fn($d) => !$d['engagement_id']);
    $byEng = [];
    foreach ($agreements as $d) if ($d['engagement_id']) $byEng[$d['ref'] . ' · ' . ($d['eng_title'] ?: '')][] = $d;
    $offerTab = client_scope_allows('hr') && client_offer_access($cid);
    $tab = in_array(input('tab'), ['all', 'engagements', 'offers'], true) ? input('tab') : 'all';
    if ($tab === 'offers' && !$offerTab) $tab = 'all';

    // Signature status per document (one query).
    $sigMap = [];
    if ($agreements) foreach (all('SELECT document_id, party, signer_name, signed_at FROM hrm_client_signatures WHERE document_id IN (' . implode(',', array_map(fn($d) => (int) $d['id'], $agreements)) . ') ORDER BY id') as $s) $sigMap[$s['document_id']][$s['party']] = $s;
    $sigStatus = function (array $d) use ($sigMap): string {
        $s = $sigMap[$d['id']] ?? [];
        if (!$d['requires_signature'] && !$s) return '<span class="text-muted">Not required</span>';
        $parts = [];
        if (isset($s['client'])) $parts[] = '<span class="text-success"><i class="ri-check-line"></i> Signed by ' . e($s['client']['signer_name']) . ' · ' . fdate($s['client']['signed_at'], 'd M Y') . '</span>';
        elseif (in_array($d['status'], DOC_OPEN_SIGN, true)) $parts[] = '<span class="text-warning">Awaiting your signature</span>';
        elseif (in_array($d['status'], ['Declined', 'Change Requested', 'Expired'], true)) $parts[] = '<span class="text-danger">Not signed</span>';
        if (isset($s['company'])) $parts[] = '<span class="text-success"><i class="ri-check-line"></i> ' . e(setting('company_name')) . '</span>';
        elseif ((int) $d['countersign'] === 1 && isset($s['client'])) $parts[] = '<span class="text-warning">Awaiting our countersignature</span>';
        return implode('<br>', $parts) ?: '<span class="text-muted">Not signed</span>';
    };

    // Filters (All documents tab): type, category, status, date shared.
    $f = ['type' => (string) input('type'), 'category' => (string) input('category'), 'status' => (string) input('status'), 'from' => valid_date(input('from')), 'to' => valid_date(input('to')), 'history' => input('history') === '1'];
    $list = array_filter($agreements, function ($d) use ($f) {
        $shared = substr((string) ($d['sent_at'] ?: $d['created_at']), 0, 10);
        $type = $d['doc_type'] ?: (DOC_LEGACY_TYPES[$d['kind']] ?? $d['kind']);
        return ($f['history'] || !$d['superseded_by'])
            && ($f['type'] === '' || $type === $f['type'])
            && ($f['category'] === '' || doc_category($d) === $f['category'])
            && ($f['status'] === '' || doc_status_label($d) === $f['status'])
            && (!$f['from'] || $shared >= $f['from']) && (!$f['to'] || $shared <= $f['to']);
    });
    $typesSeen = [];
    foreach ($agreements as $d) { $t = $d['doc_type'] ?: (DOC_LEGACY_TYPES[$d['kind']] ?? $d['kind']); $typesSeen[$t] = doc_type_label($d); }
    $typeOpts = DOC_TYPES + $typesSeen;
    $toSignN = count(array_filter($agreements, fn($d) => doc_can($d, 'sign')));
    $offers = $offerTab ? offer_rows($cid) : [];

    page_start('Document Centre', ['My Services' => url('services'), 'Document Centre']);
    $row = function (array $d) {
        $sig = $d['status'] === 'Signed' ? one('SELECT signer_name, signed_at FROM hrm_client_signatures WHERE document_id = ? AND party = "client" ORDER BY id DESC LIMIT 1', [$d['id']]) : null;
        return '<a class="list-row text-body" href="' . e(url('services', ['a' => doc_can($d, 'sign') ? 'sign' : 'doc', 'id' => $d['id']])) . '">'
            . '<span class="onb-doc-icon has"><i class="' . ($d['kind'] === 'deliverable' ? 'ri-inbox-archive-line' : ($d['file'] && !$d['content'] ? 'ri-attachment-2' : 'ri-file-text-line')) . '"></i></span>'
            . '<div class="grow"><div class="title">' . e($d['title']) . ' <span class="small text-muted fw-normal">v' . (int) $d['version'] . '</span>' . ($d['superseded_by'] ? ' <span class="pill pill-neutral">Superseded</span>' : '') . '</div>'
            . '<div class="sub">' . ($sig ? 'Signed by ' . e($sig['signer_name']) . ' · ' . fdate($sig['signed_at'], 'd M Y') : 'Issued ' . fdate($d['sent_at'] ?: $d['created_at'], 'd M Y')) . '</div></div>' . ($d['kind'] === 'managed' ? doc_status_pill($d) : badge($d['status'])) . '</a>';
    }; ?>
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item"><a class="nav-link<?= $tab === 'all' ? ' active' : '' ?>" href="<?= e(url('services', ['a' => 'documents'])) ?>"><i class="ri-file-list-3-line me-1"></i>All documents<?= $toSignN ? ' <span class="pill pill-warning">' . $toSignN . ' to sign</span>' : '' ?></a></li>
      <li class="nav-item"><a class="nav-link<?= $tab === 'engagements' ? ' active' : '' ?>" href="<?= e(url('services', ['a' => 'documents', 'tab' => 'engagements'])) ?>"><i class="ri-folders-line me-1"></i>By engagement</a></li>
      <?php if ($offerTab): ?><li class="nav-item"><a class="nav-link<?= $tab === 'offers' ? ' active' : '' ?>" href="<?= e(url('services', ['a' => 'documents', 'tab' => 'offers'])) ?>"><i class="ri-mail-star-line me-1"></i>Offer letters</a></li><?php endif; ?>
    </ul>

    <?php if ($tab === 'all'): ?>
    <div class="card list-card">
      <form class="toolbar flex-wrap" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="services"><input type="hidden" name="a" value="documents">
        <select class="form-select" name="type" onchange="this.form.submit()" aria-label="Document type"><option value="">All types</option><?php foreach ($typeOpts as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['type'] === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="category" onchange="this.form.submit()" aria-label="Service category"><option value="">All categories</option><?php foreach (DOC_CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Any status</option><?php foreach (['Sent for Signature', 'Viewed', 'Partially Signed', 'Signed', 'Declined', 'Change Requested', 'Expired', 'Superseded', 'Shared', 'Accepted'] as $st): ?><option <?= $f['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select>
        <input class="form-control" type="date" name="from" value="<?= e((string) $f['from']) ?>" aria-label="Shared from" style="max-width:150px"><input class="form-control" type="date" name="to" value="<?= e((string) $f['to']) ?>" aria-label="Shared to" style="max-width:150px">
        <label class="small text-nowrap"><input type="checkbox" name="history" value="1" <?= $f['history'] ? 'checked' : '' ?> onchange="this.form.submit()"> Earlier versions</label>
        <button class="btn btn-light border">Filter</button><span class="ms-auto small text-muted"><?= count($list) ?> document(s)</span></form>
      <?php if (!$list): empty_state('ri-file-shield-2-line', 'No documents', $agreements ? 'No document matches these filters.' : 'Agreements shared with your company appear here.'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="client-docs"><thead><tr><th>Title</th><th>Type</th><th>Category</th><th>Engagement</th><th>Version</th><th>Date shared</th><th>Status</th><th>Signature status</th><th class="text-end">Actions</th></tr></thead><tbody>
      <?php foreach ($list as $d): $view = url('services', ['a' => 'doc', 'id' => $d['id']]); ?>
        <tr><td><a class="fw-semibold" href="<?= e($view) ?>"><?= e($d['title']) ?></a></td><td class="small"><?= e(doc_type_label($d)) ?></td><td class="small"><?= e(DOC_CATEGORIES[doc_category($d) ?? ''] ?? '—') ?></td>
          <td class="small"><?= $d['engagement_id'] ? e($d['ref']) : 'Company-level' ?></td><td>v<?= (int) $d['version'] ?></td><td class="small text-nowrap"><?= fdate($d['sent_at'] ?: $d['created_at'], 'd M Y') ?></td>
          <td><?= $d['kind'] === 'managed' ? doc_status_pill($d) : badge($d['status']) ?></td><td class="small"><?= $sigStatus($d) ?></td>
          <td class="text-end text-nowrap">
            <?php if (doc_can($d, 'sign')): ?><a class="btn btn-sm btn-success" href="<?= e(url('services', ['a' => 'sign', 'id' => $d['id']])) ?>"><i class="ri-quill-pen-line me-1"></i>Sign</a><?php endif; ?>
            <a class="btn btn-sm btn-light border" href="<?= e($view) ?>"><?= $d['status'] === 'Signed' ? 'View signed copy' : 'View' ?></a>
            <?php if (doc_can($d, 'download') && $d['content'] !== null && $d['kind'] === 'managed'): ?><a class="btn btn-sm btn-icon" href="<?= e(url('services', ['a' => 'docfile', 'id' => $d['id']])) ?>" title="Download .docx" aria-label="Download"><i class="ri-download-2-line"></i></a><?php endif; ?>
            <?php if (doc_can($d, 'respond')): ?><button type="button" class="btn btn-sm btn-icon text-warning" title="Request changes" aria-label="Request changes" data-post="<?= e(url('services', ['a' => 'respond'])) ?>" data-prompt="What should be changed?" data-fields='<?= e(json_encode(['id' => $d['id'], 'kind' => 'changes'])) ?>'><i class="ri-edit-box-line"></i></button>
              <button type="button" class="btn btn-sm btn-icon text-danger" title="Decline" aria-label="Decline" data-post="<?= e(url('services', ['a' => 'respond'])) ?>" data-prompt="Why are you declining this document?" data-fields='<?= e(json_encode(['id' => $d['id'], 'kind' => 'decline'])) ?>'><i class="ri-close-circle-line"></i></button><?php endif; ?>
          </td></tr>
      <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
    <div class="card card-body small text-muted mt-3"><i class="ri-shield-keyhole-line"></i> Every document keeps all its versions. Signed versions are locked and carry a signature record (who, when, verified e-mail, IP, device and a SHA-256 fingerprint of the exact text). Signatures made here are electronic signatures verified by e-mail OTP; they are not certificate-based digital signatures.</div>

    <?php elseif ($tab === 'offers'):
        // Mutually exclusive buckets: every shared offer is counted once (drafts are never shared, superseded revisions are not current).
        $bucket = fn($r) => match ($r['offer_status']) { 'Joined' => 'joined', 'Not Joined' => 'not_joined', 'Accepted' => 'accepted', 'Shared', 'Viewed', 'Generated' => 'pending', default => 'closed' };
        $cnt = ['pending' => 0, 'accepted' => 0, 'joined' => 0, 'not_joined' => 0, 'closed' => 0];
        foreach ($offers as $r) $cnt[$bucket($r)]++; ?>
      <div class="kpi-strip mb-3">
        <?= kpi('Total shared', count($offers), 'ri-mail-star-line', '#6366f1') ?>
        <?= kpi('Accepted', $cnt['accepted'], 'ri-thumb-up-line', '#0ea5e9') ?>
        <?= kpi('Pending', $cnt['pending'], 'ri-time-line', '#f59e0b') ?>
        <?= kpi('Joined', $cnt['joined'], 'ri-user-follow-line', '#16a34a') ?>
        <?= kpi('Not joined', $cnt['not_joined'], 'ri-user-unfollow-line', '#dc2626') ?>
      </div>
      <div class="card list-card">
        <?php if (!$offers): empty_state('ri-mail-star-line', 'No offer letters yet', 'Offer letters issued to candidates for your positions appear here once they are sent.'); else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="client-offers"><thead><tr><th>Candidate Name</th><th>Designation</th><th>Work Location</th><th>Joined Date</th><th class="text-end">Offer Letter</th></tr></thead><tbody>
        <?php foreach ($offers as $r): $joined = $r['joined_on'] ?: ($r['offer_status'] === 'Joined' ? $r['emp_joined'] : null); ?>
          <tr><td><div class="fw-semibold"><?= e($r['full_name'] ?: $r['cand_name']) ?></div><span class="pill pill-<?= ['Joined' => 'success', 'Accepted' => 'info', 'Not Joined' => 'danger', 'Declined' => 'danger', 'Withdrawn' => 'neutral', 'Expired' => 'neutral'][$r['offer_status']] ?? 'warning' ?>"><?= e($r['offer_status']) ?></span></td>
            <td class="small"><?= e($r['designation'] ?: '—') ?></td><td class="small"><?= e($r['work_location'] ?: '—') ?></td>
            <td class="small text-nowrap"><?= $joined ? fdate($joined, 'd M Y') : (in_array($r['offer_status'], ['Not Joined', 'Declined', 'Withdrawn', 'Expired'], true) ? '<span class="text-danger">Not Joined</span>' : '<span class="text-muted">Pending</span>') ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(url('services', ['a' => 'doc', 'id' => $r['doc_id']])) ?>"><i class="ri-eye-line me-1"></i>View Offer Letter</a></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php if ($cnt['closed']): ?><div class="card-body small text-muted border-top"><?= $cnt['closed'] ?> offer(s) declined, withdrawn or expired are included in Total shared only.</div><?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card card-body small text-muted mt-3"><i class="ri-information-line"></i> Read-only. Status and joining dates come from our onboarding records; "Pending" means the candidate has not joined yet.</div>

    <?php else: ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card"><div class="card-header-lite"><span><i class="ri-building-4-line me-1"></i>Company agreements</span><span class="small text-muted fw-normal">Signed once, apply to all requests</span></div>
          <?php foreach ($company as $d) echo $row($d); ?>
          <?php if (!$company): ?><div class="card-body small text-muted">Your NDA and service agreement appear here with your first proposal.</div><?php endif; ?></div>
        <?php foreach ($byEng as $label => $list): ?>
          <div class="card"><div class="card-header-lite"><?= e($label) ?></div><?php foreach ($list as $d) echo $row($d); ?></div>
        <?php endforeach; ?>
      </div>
      <div class="vstack gap-3">
        <?php if ($invoices): ?>
        <div class="card"><div class="card-header-lite">Invoices &amp; receipts</div>
          <?php foreach (array_slice($invoices, 0, 12) as $i): ?><a class="list-row text-body" href="<?= e(url('client', ['a' => 'invoice', 'id' => $i['id']])) ?>"><div class="grow"><div class="title"><?= e($i['invoice_no']) ?></div><div class="sub"><?= fdate($i['issue_date']) ?> · <?= money($i['total'], true) ?><?= $i['ref'] ? ' · ' . e($i['ref']) : '' ?></div></div><?= badge($i['status']) ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="card card-body small text-muted"><i class="ri-shield-keyhole-line"></i> Every document keeps all its versions. Signed versions are locked and come with a signature certificate (who, when, verified e-mail, IP, device and a SHA-256 fingerprint). Use <em>Print / Save PDF</em> on any document for a PDF copy.</div>
      </div>
    </div>
    <?php endif;
    page_end();
    return;
}

/* ───────────── New / edit request ───────────── */
if ($a === 'new' || $a === 'edit') {
    $e = $a === 'edit' ? eng_access(int_input('id')) : null;
    if ($e && !in_array($e['status'], ['Draft', 'Info Requested'], true)) redirect(url('services', ['a' => 'view', 'id' => $e['id']]));
    $service = $e['service'] ?? (string) input('service');
    if (!isset($services[$service])) {
        page_start('New request', ['My Services' => url('services'), 'New request']); ?>
        <h5 class="fw-bold mb-3">Which service do you need?</h5>
        <div class="eng-svc-grid">
          <?php foreach ($services as $k => $s): ?>
            <a class="card card-body eng-svc text-body" href="<?= e(url('services', ['a' => 'new', 'service' => $k])) ?>"><span class="eng-svc-icon"><i class="<?= $s['icon'] ?>"></i></span>
              <h5 class="fw-bold mt-2 mb-1"><?= e($s['label']) ?></h5><p class="small text-muted mb-2"><?= e($s['blurb']) ?></p>
              <div class="small"><strong>You'll fill in:</strong> <?= e($s['form']) ?> form<br><strong>Documents:</strong> <?= e(implode(', ', array_map(fn($kk) => ENG_DOC_KINDS[$kk]['label'], ENG_REQUIRED_DOCS[$k]))) ?></div>
              <span class="btn btn-primary btn-sm mt-3 align-self-start">Start <i class="ri-arrow-right-line"></i></span></a>
          <?php endforeach; ?>
        </div>
        <?php page_end(); return;
    }
    $vals = $_SESSION['_old']['f'] ?? ($e['form_data'] ?? []);
    $s = ENG_SERVICES[$service];
    page_start($e ? ($e['ref'] ?: 'Draft') . ' · ' . $s['form'] : 'New ' . strtolower($s['form']), ['My Services' => url('services'), $s['label']]);
    if ($e && $e['status'] === 'Info Requested'): ?>
      <div class="alert alert-warning d-flex gap-2"><i class="ri-question-answer-line fs-5"></i><div><strong>Our team needs more information</strong><div class="small"><?= nl2br(e($e['info_note'])) ?></div></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('services', ['a' => 'save'])) ?>" enctype="multipart/form-data" class="vstack gap-3" data-eng-form novalidate>
      <?= csrf_field() ?><input type="hidden" name="service" value="<?= e($service) ?>"><?php if ($e): ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><?php endif; ?>
      <?php foreach (ENG_FORMS[$service] as $section => $fields): ?>
        <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="<?= $s['icon'] ?>"></i></span><div><h6><?= e($section) ?></h6></div></div>
          <div class="form-block-body"><div class="row g-3"><?php foreach ($fields as $k => $f) echo eng_field($k, $f, $vals[$k] ?? (($f['type'] ?? '') === 'checks' ? [] : '')); ?></div></div></section>
      <?php endforeach; ?>
      <div class="card card-body small text-muted"><i class="ri-information-line"></i> Submitting does not start any work or create any commitment. We review your request and send a proposal with the agreements; the <?= e(strtolower($s['output'])) ?> is activated only after you approve and sign them<?= setting('eng_advance_percent', '30') > 0 ? ' and the advance is paid' : '' ?>.</div>
      <div class="form-actions sticky-actions"><a class="btn btn-light border" href="<?= e(url('services')) ?>">Cancel</a>
        <button class="btn btn-light border ms-auto" name="do" value="draft"><i class="ri-save-3-line me-1"></i>Save draft</button>
        <button class="btn btn-primary" name="do" value="submit"><i class="ri-send-plane-2-line me-1"></i><?= $e && $e['status'] === 'Info Requested' ? 'Send update' : 'Submit request' ?></button></div>
    </form>
    <?php
    page_end(['js/engagements.js']);
    return;
}

/* ───────────── One request ───────────── */
if ($a === 'view') {
    $e = eng_access(int_input('id'));
    $docs = eng_required_docs($e);
    $gates = eng_gates($e);
    $inv = eng_advance_invoice($e);
    $invoices = all("SELECT * FROM hrm_invoices WHERE engagement_id = ? AND status <> 'Cancelled' ORDER BY issue_date DESC, id DESC", [$e['id']]);
    $milestones = all('SELECT * FROM hrm_engagement_milestones WHERE engagement_id = ? ORDER BY sort, due_date, id', [$e['id']]);
    $msgs = all('SELECT m.*, l.username, l.usertype FROM hrm_engagement_messages m LEFT JOIN it_login l ON l.id = m.login_id WHERE m.engagement_id = ? AND m.internal = 0 ORDER BY m.id', [$e['id']]);
    $deliverables = all("SELECT * FROM hrm_client_documents WHERE engagement_id = ? AND kind IN ('deliverable','file') AND status <> 'Void' ORDER BY id DESC", [$e['id']]);
    $del = eng_delivery($e);
    $s = ENG_SERVICES[$e['service']];
    $actions = in_array($e['status'], ['Draft', 'Info Requested'], true) ? '<a class="btn btn-primary" href="' . e(url('services', ['a' => 'edit', 'id' => $e['id']])) . '"><i class="ri-pencil-line me-1"></i>' . ($e['status'] === 'Draft' ? 'Continue editing' : 'Add information') . '</a>' : '';
    page_start(eng_title($e), ['My Services' => url('services'), $e['ref'] ?: 'Draft'], $actions);
    $banner = [
        'Draft' => ['info', 'This request is a draft. Submit it when ready.'],
        'Submitted' => ['info', 'Our team is reviewing your request. We\'ll send a proposal and the agreements to sign.'],
        'Info Requested' => ['warning', 'We need a little more information: ' . $e['info_note']],
        'Proposal Sent' => ['warning', 'Your proposal and agreements are ready. Review and sign them below.'],
        'Awaiting Payment' => ['warning', 'Thank you for signing. The advance invoice' . ($inv ? ' ' . $inv['invoice_no'] : '') . ' needs to be paid before we start.'],
        'Awaiting Authorization' => ['info', 'Everything is in place. Our management is giving the final authorisation; you\'ll be notified when the ' . strtolower($s['output']) . ' goes live.'],
        'Active' => ['success', 'Your ' . strtolower($s['output']) . ' is active. Follow progress and reports below.'],
        'Delivered' => ['success', $e['service'] === 'hr' ? 'Positions filled. Thank you for hiring with us!' : 'The project has been delivered.'],
        'Closed' => ['success', 'This ' . strtolower($s['output']) . ' is closed' . ($e['closure_note'] ? ': ' . $e['closure_note'] : '.')],
        'Cancelled' => ['danger', 'This request was cancelled.'], 'Declined' => ['danger', 'We are unable to take up this request' . ($e['closure_note'] ? ': ' . $e['closure_note'] : '.')],
    ][$e['status']] ?? ['info', '']; ?>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex flex-wrap gap-3 align-items-center mb-3"><span class="eng-svc-icon sm"><i class="<?= $s['icon'] ?>"></i></span>
        <div class="grow"><div class="small text-muted"><?= e($s['label']) ?> · <?= e($e['ref'] ?: 'Draft') ?><?= $e['submitted_at'] ? ' · submitted ' . fdate($e['submitted_at']) : '' ?></div><h5 class="fw-bold m-0"><?= e(eng_title($e)) ?> <?= badge($e['status']) ?></h5></div>
        <?php if ($e['owner_name']): ?><div class="small text-end"><span class="text-muted">Your contact</span><br><strong><?= e($e['owner_name']) ?></strong></div><?php endif; ?></div>
      <?= eng_tracker($e) ?>
    </div></div>
    <div class="alert alert-<?= $banner[0] ?> small"><i class="ri-information-line me-1"></i><?= e($banner[1]) ?></div>

    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php if (in_array($e['status'], [...ENG_PROPOSAL_STAGE, 'Active', 'Delivered', 'Closed'], true)): ?>
        <div class="card"><div class="card-header-lite">Approvals &amp; documents</div>
          <?php foreach ($docs as $kind => $d): ?>
            <div class="onb-doc"><span class="onb-doc-icon <?= $d ? 'has' : '' ?>"><i class="<?= $d && $d['status'] === 'Signed' ? 'ri-shield-check-fill' : 'ri-file-text-line' ?>"></i></span>
              <div class="grow"><div class="fw-semibold"><?= e(ENG_DOC_KINDS[$kind]['label']) ?> <?= $d ? '<span class="small text-muted fw-normal">v' . (int) $d['version'] . '</span>' : '' ?></div>
                <div class="small text-muted"><?= ENG_DOC_KINDS[$kind]['scope'] === 'company' ? 'Company-level: signed once for all requests' : 'For this ' . strtolower($s['output']) ?></div></div>
              <?= $d ? badge($d['status']) : '<span class="pill pill-neutral">Not issued yet</span>' ?>
              <?php if ($d && $d['status'] === 'Awaiting Signature' && is_client_signatory()): ?><a class="btn btn-sm btn-success" href="<?= e(url('services', ['a' => 'sign', 'id' => $d['id']])) ?>"><i class="ri-quill-pen-line me-1"></i>Review &amp; sign</a>
              <?php elseif ($d): ?><a class="btn btn-sm btn-light border" href="<?= e(url('services', ['a' => 'doc', 'id' => $d['id']])) ?>">View</a><?php endif; ?></div>
          <?php endforeach; ?>
          <?php if (!is_client_signatory() && $e['status'] === 'Proposal Sent'): ?><div class="card-body small text-muted border-top">Only your company's authorised signatory can sign. They have been e-mailed.</div><?php endif; ?>
          <?php if ($e['status'] === 'Proposal Sent'): ?>
            <details class="card-body border-top small"><summary class="text-muted">Not happy with the proposal?</summary>
              <form method="post" action="<?= e(url('services', ['a' => 'decline', 'id' => $e['id']])) ?>" class="mt-2 vstack gap-2"><?= csrf_field() ?><textarea class="form-control" name="body" rows="2" placeholder="What should change? (scope, price, timeline…)" required></textarea>
                <div><button class="btn btn-sm btn-outline-danger">Ask for a revised proposal</button></div></form></details>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (in_array($e['status'], ['Active', 'Delivered', 'Closed'], true)): ?>
        <div class="card" id="delivery"><div class="card-header-lite"><?= $e['service'] === 'hr' ? 'Hiring progress' : 'Project progress' ?>
          <?php if ($e['service'] === 'hr' && $e['job_id'] && can('client_hiring')): ?><a class="small fw-normal" href="<?= e(url('client', ['a' => 'job', 'id' => $e['job_id']])) ?>">Candidates →</a>
          <?php elseif ($e['service'] === 'it' && $e['project_id'] && can('client_projects')): ?><a class="small fw-normal" href="<?= e(url('client', ['a' => 'project', 'id' => $e['project_id']])) ?>">Project &amp; tasks →</a><?php endif; ?></div>
          <div class="card-body">
            <div class="d-flex justify-content-between small mb-1"><span><?= $e['service'] === 'hr' ? 'Positions filled' : 'Completion' ?></span><strong><?= (int) $del['pct'] ?>%</strong></div>
            <div class="progress mb-3" style="height:10px"><div class="progress-bar" style="width:<?= (int) $del['pct'] ?>%"></div></div>
            <div class="eng-kpis">
              <?php if ($del['kind'] === 'hr' && isset($del['openings'])): foreach (['Openings' => $del['openings'], 'Profiles' => $del['profiles'], 'Shortlisted' => $del['shortlisted'], 'Interviews' => $del['interviews'], 'Offers' => $del['offered'], 'Joined' => $del['joined']] as $l => $n): ?>
                <div><strong><?= (int) $n ?></strong><small><?= $l ?></small></div><?php endforeach;
              elseif ($del['kind'] === 'it' && isset($del['tasks'])): foreach (['Tasks done' => $del['done'] . '/' . $del['tasks'], 'Deliverables' => $del['deliverables'], 'Accepted' => $del['accepted'], 'Status' => $del['project_status']] as $l => $n): ?>
                <div><strong><?= e((string) $n) ?></strong><small><?= $l ?></small></div><?php endforeach; endif; ?>
            </div>
          </div>
          <?php foreach ($deliverables as $d): ?>
            <div class="onb-doc"><span class="onb-doc-icon has"><i class="ri-inbox-archive-line"></i></span>
              <div class="grow"><div class="fw-semibold"><?= e($d['title']) ?> <span class="small text-muted fw-normal">v<?= (int) $d['version'] ?></span></div><div class="small text-muted"><?= fdate($d['created_at']) ?><?= $d['review_note'] ? ' · ' . e($d['review_note']) : '' ?></div>
                <?php if ($d['file']): ?><a class="small" href="<?= e(file_url($d['file'])) ?>" target="_blank" rel="noopener"><i class="ri-download-2-line"></i> <?= e($d['original_name'] ?: 'Download') ?></a><?php endif; ?></div>
              <?= badge($d['status']) ?>
              <?php if ($d['kind'] === 'deliverable' && in_array($d['status'], ['Shared', 'Changes Requested'], true)): ?>
                <form method="post" action="<?= e(url('services', ['a' => 'deliverable', 'id' => $e['id']])) ?>" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="doc" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-sm btn-soft-success" name="decision" value="accept">Accept</button>
                  <button type="button" class="btn btn-sm btn-outline-warning" data-post="<?= e(url('services', ['a' => 'deliverable', 'id' => $e['id']])) ?>" data-prompt="What needs to change?" data-fields='<?= e(json_encode(['doc' => $d['id'], 'decision' => 'changes'])) ?>'>Request changes</button></form>
              <?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card"><div class="card-header-lite"><?= e($s['form']) ?></div><div class="card-body"><?= eng_answers_html($e) ?: '<span class="text-muted small">Not filled in yet.</span>' ?></div></div>

        <div class="card" id="discussion"><div class="card-header-lite">Discussion, reports &amp; meetings</div>
          <div class="eng-thread">
            <?php foreach ($msgs as $m): $staff = $m['usertype'] && normalize_role($m['usertype']) !== 'client'; ?>
              <div class="eng-msg <?= $staff ? 'staff' : 'mine' ?> kind-<?= e($m['kind']) ?>">
                <div class="eng-msg-head"><strong><?= e($staff ? ($m['username'] . ' · ' . setting('company_name')) : $m['username']) ?></strong>
                  <?= $m['kind'] !== 'comment' ? '<span class="pill pill-' . ($m['kind'] === 'escalation' ? 'danger' : 'info') . '">' . e(ucfirst($m['kind'])) . '</span>' : '' ?><small><?= time_ago($m['created_at']) ?></small></div>
                <?php if ($m['kind'] === 'meeting'): ?><div class="eng-meeting"><i class="ri-calendar-event-line"></i> <?= fdate($m['meeting_at'], 'l, d M Y · h:i A') ?><?= $m['meeting_link'] ? ' · <a href="' . e($m['meeting_link']) . '" target="_blank" rel="noopener">Join link</a>' : '' ?></div><?php endif; ?>
                <div><?= nl2br(e($m['body'])) ?></div>
                <?php if ($m['attachment']): ?><a class="small" href="<?= e(file_url($m['attachment'])) ?>" target="_blank" rel="noopener"><i class="ri-attachment-2"></i> Attachment</a><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$msgs): ?><p class="small text-muted mb-0">No messages yet. Ask a question or share an update below.</p><?php endif; ?>
          </div>
          <?php if (!in_array($e['status'], ['Cancelled'], true)): ?>
          <form method="post" action="<?= e(url('services', ['a' => 'comment', 'id' => $e['id']])) ?>" enctype="multipart/form-data" class="card-body border-top vstack gap-2"><?= csrf_field() ?>
            <textarea class="form-control" name="body" rows="2" placeholder="Write a message to our team…" required maxlength="4000"></textarea>
            <div class="d-flex flex-wrap gap-2 align-items-center"><input class="form-control form-control-sm" style="max-width:260px" type="file" name="attachment">
              <button class="btn btn-primary btn-sm ms-auto"><i class="ri-send-plane-line me-1"></i>Send</button>
              <?php if ($e['status'] !== 'Draft'): ?><button type="button" class="btn btn-outline-danger btn-sm" data-post="<?= e(url('services', ['a' => 'escalate', 'id' => $e['id']])) ?>" data-prompt="Describe the issue you want to escalate to senior management."><i class="ri-alarm-warning-line me-1"></i>Escalate</button><?php endif; ?></div>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="vstack gap-3">
        <?php if (in_array($e['status'], ENG_PROPOSAL_STAGE, true)): ?>
        <div class="card"><div class="card-header-lite">Before we start</div>
          <?php foreach ($gates as [$label, $ok]): ?><div class="onb-check <?= $ok ? 'done' : '' ?>"><i class="<?= $ok ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line' ?>"></i><div class="grow small"><?= e($label) ?></div></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ((float) $e['advance_amount'] > 0 || $invoices || $milestones): ?>
        <div class="card"><div class="card-header-lite">Billing</div><div class="card-body small vstack gap-2">
          <?php if ((float) $e['advance_amount'] > 0): ?><div class="d-flex justify-content-between"><span class="text-muted">Advance</span><strong><?= money(eng_advance_with_tax($e), true) ?></strong></div><?php endif; ?>
          <?php foreach ($milestones as $m): ?><div class="d-flex justify-content-between"><span><?= e($m['title']) ?><br><small class="text-muted"><?= $m['due_date'] ? 'Due ' . fdate($m['due_date']) : '' ?></small></span><span class="text-end"><?= money($m['amount']) ?><br><?= badge($m['status']) ?></span></div><?php endforeach; ?>
        </div>
          <?php foreach ($invoices as $i): ?><a class="list-row text-body" href="<?= e(url('client', ['a' => 'invoice', 'id' => $i['id']])) ?>"><div class="grow"><div class="title"><?= e($i['invoice_no']) ?></div><div class="sub"><?= money($i['total'], true) ?> · due <?= fdate($i['due_date']) ?></div></div><?= badge(invoice_overdue($i) ? 'Overdue' : $i['status']) ?></a><?php endforeach; ?>
          <?php if (setting('invoice_bank_details') && array_filter($invoices, fn($i) => $i['status'] !== 'Paid')): ?><div class="card-body border-top small"><div class="text-muted mb-1">Pay by bank transfer / UPI</div><?= nl2br(e(setting('invoice_bank_details'))) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (in_array($e['status'], ['Delivered', 'Closed'], true)): ?>
        <div class="card card-body"><h6 class="fw-bold"><i class="ri-star-smile-line text-warning me-1"></i>How did we do?</h6>
          <?php if ($e['client_rating']): ?><div><?= str_repeat('★', (int) $e['client_rating']) . str_repeat('☆', 5 - (int) $e['client_rating']) ?></div><p class="small text-muted mb-0 mt-1"><?= e($e['client_feedback'] ?: 'Thank you!') ?></p>
          <?php else: ?><form method="post" action="<?= e(url('services', ['a' => 'feedback', 'id' => $e['id']])) ?>" class="vstack gap-2"><?= csrf_field() ?>
            <select class="form-select form-select-sm" name="rating" required><option value="">Rating…</option><?php for ($r = 5; $r >= 1; $r--): ?><option value="<?= $r ?>"><?= str_repeat('★', $r) ?></option><?php endfor; ?></select>
            <textarea class="form-control form-control-sm" name="body" rows="2" placeholder="Comments (optional)"></textarea><button class="btn btn-sm btn-primary">Send feedback</button></form><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (in_array($e['status'], ['Draft', 'Submitted', 'Info Requested'], true)): ?>
          <div class="text-center"><?= str_replace('<button', '<button data-prompt="Why are you cancelling? (optional)"', post_button(url('services', ['a' => 'cancel', 'id' => $e['id']]), '<i class="ri-close-circle-line me-1"></i>Cancel this request', 'btn btn-sm btn-link text-danger')) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page_end(['js/engagements.js']);
    return;
}

/* ───────────── My services (index) ───────────── */
$rows = array_values(array_filter(all('SELECT * FROM hrm_engagements WHERE client_id = ? ORDER BY FIELD(status,"Info Requested","Proposal Sent","Awaiting Payment","Draft","Submitted","Awaiting Authorization","Active","Delivered","Closed","Declined","Cancelled"), id DESC', [$cid]),
    fn($r) => client_scope_allows($r['service'])));
$toSign = is_client_signatory() ? array_filter(eng_docs_awaiting($cid), fn($d) => doc_can($d, 'sign')) : [];
page_start('My Services', ['My Services'], '<a class="btn btn-light border" href="' . e(url('services', ['a' => 'documents'])) . '"><i class="ri-folder-shield-2-line me-1"></i>Document Centre</a>'
    . '<a class="btn btn-primary" href="' . e(url('services', ['a' => 'new'])) . '"><i class="ri-add-line me-1"></i>New request</a>');
if ($toSign): ?>
  <a class="action-banner tone-warning mb-3" href="<?= e(url('services', ['a' => 'sign', 'id' => array_values($toSign)[0]['id']])) ?>"><i class="ri-quill-pen-line"></i>
    <div class="grow"><strong><?= count($toSign) ?> document<?= count($toSign) > 1 ? 's' : '' ?> waiting for your signature</strong><div class="small"><?= e(implode(' · ', array_column(array_slice($toSign, 0, 3), 'title'))) ?></div></div><span class="btn btn-sm btn-warning">Sign now</span></a>
<?php endif;
if (!$rows): ?>
  <div class="eng-svc-grid">
    <?php foreach ($services as $k => $s): ?>
      <a class="card card-body eng-svc text-body" href="<?= e(url('services', ['a' => 'new', 'service' => $k])) ?>"><span class="eng-svc-icon"><i class="<?= $s['icon'] ?>"></i></span>
        <h5 class="fw-bold mt-2 mb-1"><?= e($s['label']) ?></h5><p class="small text-muted mb-0"><?= e($s['blurb']) ?></p><span class="btn btn-primary btn-sm mt-3 align-self-start">Start a request <i class="ri-arrow-right-line"></i></span></a>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="vstack gap-3">
    <?php foreach ($rows as $r): $r['form_data'] = json_decode((string) $r['form'], true) ?: []; $s = ENG_SERVICES[$r['service']]; ?>
      <a class="card card-body text-body eng-row" href="<?= e(url('services', ['a' => in_array($r['status'], ['Draft'], true) ? 'edit' : 'view', 'id' => $r['id']])) ?>">
        <div class="d-flex flex-wrap gap-3 align-items-center"><span class="eng-svc-icon sm"><i class="<?= $s['icon'] ?>"></i></span>
          <div class="grow"><div class="small text-muted"><?= e($s['label']) ?> · <?= e($r['ref'] ?: 'Draft') ?></div><div class="fw-bold"><?= e(eng_title($r)) ?></div></div><?= badge($r['status']) ?></div>
        <div class="mt-3"><?= eng_tracker($r) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif;
page_end();
