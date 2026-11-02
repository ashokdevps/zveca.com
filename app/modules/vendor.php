<?php
/**
 * Sub-vendor portal — what a staffing partner login can see. Every query is scoped to the
 * vendor's own agency (my_vendor_id()) and to jobs shared with it (vendor_jobs_where()).
 *   a=jobs / job           (vendor_jobs)        open requirements and full JDs
 *   a=submit (POST)        (vendor_submissions) submit a candidate with resume, duplicate-checked
 *   a=submissions / submission                   track every profile they sent
 *   a=agency                                     their agency details and terms
 *   a=documents / document (vendor_documents)    agreements shared with the agency (view, sign by e-mail OTP, respond) and their own Word uploads
 * Vendors never see internal notes, ratings, client feedback or other sources' candidates.
 */

if (!is_vendor()) {
    redirect(url());
}
$vid = my_vendor_id();
$agency = user()['vendor'];
// A partner also sees the submissions of its linked sub-vendors (shared ownership); a sub-vendor sees its own.
$myIds = vendor_owner($vid)[1] === null ? vendor_group($vid) : [$vid];
$myIn = implode(',', array_map('intval', $myIds));
$a = $_GET['a'] ?? (can('vendor_jobs') ? 'jobs' : 'submissions');

/* ───────────── Documents & agreements (vendor_documents) ───────────── */
if (in_array($a, ['documents', 'document', 'doc_upload', 'doc_save', 'doc_submit', 'doc_version', 'doc_sign_otp', 'doc_sign', 'doc_respond', 'doc_download'], true)) {
    authorize('vendor_documents');
    $vdoc = function (int $id, string $act = 'view'): array {
        $d = doc_find($id);
        if (!$d || !doc_can($d, 'view')) not_found();
        if ($act !== 'view' && !doc_can($d, $act)) authorize('__never__');
        return $d;
    };
    $vback = fn(int $id, string $type, string $msg) => [flash($type, $msg), redirect(url('vendor', ['a' => 'document', 'id' => $id]))];

    if ($a === 'doc_upload' && is_post()) {
        verify_csrf();
        try {
            [$path, $ext, $html, $orig, $sha] = doc_store_word('file');
            $type = (string) input('doc_type');
            $id = doc_create(['title' => mb_substr(trim((string) input('title')), 0, 190) ?: (DOC_TYPES[$type] ?? 'Document') . ' — ' . $agency['name'], 'doc_type' => $type, 'service_category' => (string) input('service_category'),
                'vendor_id' => $vid, 'file' => $path, 'original_name' => $orig, 'file_format' => $ext, 'file_hash' => $sha, 'source' => 'upload', 'content' => $html !== '' ? $html : null], 'uploaded', $orig . ' (' . strtoupper($ext) . ')');
        } catch (RuntimeException $ex) {
            flash('danger', e($ex->getMessage()));
            redirect(url('vendor', ['a' => 'documents']));
        }
        $vback($id, 'success', 'Uploaded as a <strong>Draft</strong>. Review it, then submit it to us for review.');
    }
    if ($a === 'doc_save' && is_post()) {
        verify_csrf();
        $d = $vdoc(int_input('id'), 'edit');
        try { $nid = doc_save_content($d, (string) ($_POST['content'] ?? ''), mb_substr(trim((string) input('note')), 0, 200)); } catch (RuntimeException $ex) { $vback((int) $d['id'], 'danger', e($ex->getMessage())); }
        $vback($nid, 'success', 'Saved.');
    }
    if ($a === 'doc_version' && is_post()) {
        verify_csrf();
        $d = $vdoc(int_input('id'), 'version');
        try {
            [$path, $ext, $html, $orig, $sha] = doc_store_word('file');
            $nid = doc_new_version($d, ['file' => $path, 'original_name' => $orig, 'file_format' => $ext, 'file_hash' => $sha, 'source' => 'upload', 'content' => $html !== '' ? $html : null], 'Revised Word document uploaded by the vendor: ' . $orig);
        } catch (RuntimeException $ex) { $vback((int) $d['id'], 'danger', e($ex->getMessage())); }
        $vback($nid, 'success', 'Revised document uploaded as a new version.');
    }
    if ($a === 'doc_submit' && is_post()) {
        verify_csrf();
        $d = $vdoc(int_input('id'), 'review');
        update('hrm_client_documents', ['status' => 'In Review', 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
        doc_event((int) $d['id'], 'review', 'Submitted by ' . $agency['name']);
        doc_notify_once("docreview:{$d['id']}:{$d['version']}", doc_staff_emails($d), 'Vendor document for review: ' . $d['title'], $agency['name'] . ' submitted "' . $d['title'] . '" (version ' . $d['version'] . ') for review: ' . site_origin() . url('documents', ['a' => 'view', 'id' => $d['id']]));
        $vback((int) $d['id'], 'success', 'Submitted. We will review it and reply here.');
    }
    if ($a === 'doc_respond' && is_post()) {
        verify_csrf();
        $d = $vdoc(int_input('id'), 'respond');
        $note = trim((string) input('note'));
        if (mb_strlen($note) < 5) $vback((int) $d['id'], 'danger', 'Please tell us what should change (or why you are declining).');
        doc_respond($d, input('kind') === 'decline' ? 'decline' : 'changes', $note);
        $vback((int) $d['id'], 'success', 'Your response has been sent.');
    }
    if ($a === 'doc_download') {
        $d = $vdoc(int_input('id'), 'download');
        if ($d['content'] === null) not_found();
        doc_event((int) $d['id'], 'downloaded', '.docx of version ' . $d['version'], 'vendor');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . (trim(preg_replace('/[^A-Za-z0-9]+/', '-', $d['title']), '-') ?: 'document') . '-v' . (int) $d['version'] . '.docx"');
        header('Cache-Control: private, no-store');
        echo html_to_docx((string) $d['content'], '');
        exit;
    }
    // Signing: e-mail OTP bound to this exact text (SHA-256) + drawn signature, as for clients.
    if ($a === 'doc_sign_otp' && is_post()) {
        verify_csrf();
        $d = $vdoc(int_input('id'), 'sign');
        $email = strtolower((string) user()['email']);
        if ($wait = login_lockout_minutes($email, 'vsign_send')) $vback((int) $d['id'], 'danger', "Too many codes requested. Try again in $wait minute(s).");
        record_login_attempt($email, false, 'vsign_send');
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['vsign'][$d['id']] = ['hash' => hash('sha256', $d['id'] . '|' . $d['content_hash'] . '|' . $otp), 'expires' => time() + 600, 'tries' => 0];
        send_mail(user()['email'], "$otp is your signing code for {$d['title']}", "Hello " . user()['username'] . ",\n\nYour one-time code to sign \"{$d['title']}\" (version {$d['version']}) is: $otp\n\nIt is valid for 10 minutes. Do not share it.\n\n— " . setting('company_name'));
        $vback((int) $d['id'], 'info', 'We e-mailed a 6-digit signing code to ' . e(mask_email(user()['email'])) . '.');
    }
    if ($a === 'doc_sign' && is_post()) {
        verify_csrf();
        $d = $vdoc(int_input('id'), 'sign');
        $s = $_SESSION['vsign'][$d['id']] ?? null;
        $email = strtolower((string) user()['email']);
        try {
            if (!$s || $s['expires'] < time()) throw new RuntimeException('Request a signing code first (it is valid for 10 minutes).');
            if (++$_SESSION['vsign'][$d['id']]['tries'] > 5 || login_lockout_minutes($email, 'vsign_otp')) { unset($_SESSION['vsign'][$d['id']]); throw new RuntimeException('Too many wrong codes. Request a new code.'); }
            if (!hash_equals($s['hash'], hash('sha256', $d['id'] . '|' . $d['content_hash'] . '|' . preg_replace('/\D+/', '', (string) input('otp'))))) { record_login_attempt($email, false, 'vsign_otp'); throw new RuntimeException('The signing code is not correct.'); }
            $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string) input('signed_name'))), 0, 150);
            $desig = mb_substr(trim((string) input('signer_designation')), 0, 120);
            if (mb_strlen($name) < 3 || $desig === '') throw new RuntimeException('Enter your full name and designation.');
            if (empty($_POST['sign_consent'])) throw new RuntimeException('Tick the box to confirm you are authorised to sign for your agency.');
            if (val("SELECT 1 FROM hrm_client_signatures WHERE document_id = ? AND party = 'vendor'", [$d['id']])) throw new RuntimeException('Your agency has already signed this version.');
            $sig = onb_store_signature((string) input('signature'), (int) $d['id'], 'vendor', 'clientdocs', 'vsig');
        } catch (RuntimeException $ex) { $vback((int) $d['id'], 'danger', e($ex->getMessage())); }
        record_login_attempt($email, true, 'vsign_otp');
        unset($_SESSION['vsign'][$d['id']]);
        db()->beginTransaction();
        doc_store_sig_record($d, 'vendor', $name, $desig, (string) user()['email'], 'otp_draw', $sig, date('Y-m-d H:i:s'));
        doc_after_party_sign($d);
        doc_event((int) $d['id'], 'signed', "$name ($desig) · e-mail OTP + drawn signature · SHA-256 " . substr((string) $d['content_hash'], 0, 16) . '…', 'vendor');
        record_consent((int) user()['id'], null, 'esign:vendor:' . $d['id'] . ':v' . $d['version'], 'I confirm I am authorised to sign "' . $d['title'] . '" on behalf of ' . $agency['name'] . '.');
        db()->commit();
        doc_notify_once("docvsigned:{$d['id']}:{$d['version']}", doc_staff_emails($d), 'Vendor signed: ' . $d['title'], "$name ($desig) of {$agency['name']} signed \"{$d['title']}\" (version {$d['version']}).");
        $vback((int) $d['id'], 'success', '<strong>Signed.</strong> Your signature is recorded against this exact version.');
    }

    if ($a === 'document') {
        $d = $vdoc(int_input('id'));
        doc_mark_viewed($d);
        $canEdit = doc_can($d, 'edit') && !($d['file_format'] === 'doc' && $d['source'] === 'upload');
        $sent = $_SESSION['vsign'][$d['id']] ?? null;
        page_start($d['title'], ['Documents' => url('vendor', ['a' => 'documents']), 'Version ' . $d['version']],
            (doc_can($d, 'download') && $d['content'] !== null ? '<a class="btn btn-light border" href="' . e(url('vendor', ['a' => 'doc_download', 'id' => $d['id']])) . '"><i class="ri-download-2-line me-1"></i>Download (.docx)</a><button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>' : ''));
        if ($d['superseded_by']) echo '<div class="alert alert-warning small"><i class="ri-git-branch-line me-1"></i>A newer version exists. <a href="' . e(url('vendor', ['a' => 'document', 'id' => $d['superseded_by']])) . '">Open the latest version</a>.</div>';
        if (in_array($d['status'], ['Declined', 'Change Requested'], true) && $d['review_note']) echo '<div class="alert alert-warning small">' . e($d['status']) . ': ' . e($d['review_note']) . '</div>'; ?>
        <div class="grid grid-3">
          <div class="span-2">
            <?php if ($canEdit): ?>
              <form class="card" method="post" action="<?= e(url('vendor', ['a' => 'doc_save'])) ?>" data-doc-editor><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <div class="doc-toolbar" role="toolbar" aria-label="Formatting"><?php foreach ([['bold', 'ri-bold', 'Bold'], ['italic', 'ri-italic', 'Italic'], ['underline', 'ri-underline', 'Underline'], ['h2', 'ri-h-2', 'Heading'], ['p', 'ri-text', 'Paragraph'], ['insertUnorderedList', 'ri-list-unordered', 'Bullets'], ['insertOrderedList', 'ri-list-ordered', 'Numbering'], ['undo', 'ri-arrow-go-back-line', 'Undo']] as [$cmd, $ic, $t]): ?><button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $t ?>" aria-label="<?= $t ?>"><i class="<?= $ic ?>"></i></button><?php endforeach; ?><span class="ms-auto small text-muted" data-doc-state>Saved</span></div>
                <div class="doc-page"><div class="doc-edit policy-body" contenteditable="true" data-doc-body><?= $d['content'] ?></div></div><textarea name="content" hidden></textarea>
                <div class="card-body border-top d-flex gap-2"><input class="form-control form-control-sm" name="note" maxlength="200" placeholder="What changed? (optional)" style="max-width:360px"><button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save</button></div></form>
            <?php else: ?>
              <div class="card payslip m-0"><div class="card-body p-4 p-md-5"><?= $d['content'] !== null ? eng_doc_letter($d, eng_certificate($d)) : '<p class="text-muted">No preview available.</p>' ?></div></div>
            <?php endif; ?>
          </div>
          <div class="vstack gap-3">
            <div class="card card-body small"><div class="text-muted"><?= e(doc_type_label($d)) ?> · <?= e(DOC_CATEGORIES[doc_category($d) ?? ''] ?? '—') ?></div><div class="mt-1">Version <?= (int) $d['version'] ?> · <?= doc_status_pill($d) ?></div>
              <?= $d['expires_at'] && in_array($d['status'], DOC_OPEN_SIGN, true) ? '<div class="mt-1 text-muted">Sign by ' . fdate($d['expires_at'], 'd M Y') . '</div>' : '' ?></div>
            <?php if (doc_can($d, 'review')): ?><div class="card card-body"><?= post_button(url('vendor', ['a' => 'doc_submit']), '<i class="ri-send-plane-line me-1"></i>Submit to us for review', 'btn btn-primary w-100', 'Submit this document for review? You cannot edit it while it is being reviewed.', ['id' => $d['id']]) ?></div><?php endif; ?>
            <?php if (doc_can($d, 'version')): ?><form class="card card-body vstack gap-2" method="post" enctype="multipart/form-data" action="<?= e(url('vendor', ['a' => 'doc_version'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><label class="small fw-semibold">Upload a revised Word document</label><input class="form-control form-control-sm" type="file" name="file" accept=".docx,.doc" required><button class="btn btn-sm btn-light border">Create new version</button></form><?php endif; ?>
            <?php if (doc_can($d, 'sign')): ?>
              <div class="card card-body eng-sign-card"><h6 class="fw-bold"><i class="ri-quill-pen-line text-primary me-1"></i>Sign this document</h6>
                <?php if (!$sent || $sent['expires'] < time()): ?>
                  <form method="post" action="<?= e(url('vendor', ['a' => 'doc_sign_otp'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-primary w-100"><i class="ri-mail-send-line me-1"></i>E-mail me a signing code</button></form>
                <?php else: ?>
                  <form method="post" action="<?= e(url('vendor', ['a' => 'doc_sign'])) ?>" data-sign-form class="vstack gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <div><label class="form-label small mb-1" for="otp">Signing code (sent to <?= e(mask_email(user()['email'])) ?>)</label><input class="form-control otp-input" id="otp" name="otp" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"></div>
                    <div class="sig-pad" data-sig><div class="sig-head"><span class="small fw-semibold">Signature</span><div class="btn-group btn-group-sm"><button type="button" class="btn btn-light border active" data-sig-mode="draw">Draw</button><button type="button" class="btn btn-light border" data-sig-mode="type">Type</button></div></div>
                      <canvas aria-label="Signature box"></canvas><input type="text" class="form-control sig-type d-none" placeholder="Type your name" maxlength="60"><div class="sig-foot"><span class="small text-muted" data-sig-hint>Sign inside the box</span><button type="button" class="btn btn-sm btn-link p-0" data-sig-clear>Clear</button></div>
                      <input type="hidden" name="signature" data-sig-out></div>
                    <input class="form-control" name="signed_name" required placeholder="Full name" value="<?= e(str_contains((string) user()['username'], '@') ? '' : user()['username']) ?>">
                    <input class="form-control" name="signer_designation" required placeholder="Designation" value="<?= e(user()['designation'] ?? '') ?>">
                    <label class="form-check small"><input class="form-check-input" type="checkbox" name="sign_consent" value="1" required> <span class="form-check-label">I am authorised to sign this document on behalf of <strong><?= e($agency['name']) ?></strong>.</span></label>
                    <button class="btn btn-success" data-sig-submit><i class="ri-check-double-line me-1"></i>Sign document</button></form>
                <?php endif; ?>
                <div class="small text-muted mt-2">Electronic signature verified by e-mail OTP and a drawn mark, bound to the SHA-256 of this exact text. It is not a certificate-based digital signature.</div></div>
            <?php endif; ?>
            <?php if (doc_can($d, 'respond')): ?><div class="card card-body d-flex flex-row gap-2">
              <button type="button" class="btn btn-sm btn-outline-warning" data-post="<?= e(url('vendor', ['a' => 'doc_respond'])) ?>" data-prompt="What should be changed?" data-fields='<?= e(json_encode(['id' => $d['id'], 'kind' => 'changes'])) ?>'>Request changes</button>
              <button type="button" class="btn btn-sm btn-outline-danger" data-post="<?= e(url('vendor', ['a' => 'doc_respond'])) ?>" data-prompt="Why are you declining?" data-fields='<?= e(json_encode(['id' => $d['id'], 'kind' => 'decline'])) ?>'>Decline</button></div><?php endif; ?>
          </div>
        </div>
        <?php
        page_end(['js/onboarding.js', 'js/doceditor.js']);
        return;
    }

    // List
    $docs = array_values(array_filter(all("SELECT d.* FROM hrm_client_documents d WHERE d.vendor_id = ? AND d.status <> 'Void' AND d.is_current = 1 ORDER BY d.updated_at DESC, d.id DESC", [$vid]), fn($d) => doc_can($d, 'view')));
    page_start('Documents', ['Documents']);
    ?>
    <div class="grid grid-3">
      <div class="span-2 card list-card">
        <?php if (!$docs): empty_state('ri-file-shield-2-line', 'No documents yet', 'Agreements we share with your agency, and documents you upload, appear here.'); else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="vendor-docs"><thead><tr><th>Title</th><th>Type</th><th>Category</th><th>Version</th><th>Date shared</th><th>Status</th><th></th></tr></thead><tbody>
          <?php foreach ($docs as $d): $u = url('vendor', ['a' => 'document', 'id' => $d['id']]); ?>
            <tr><td><a class="fw-semibold" href="<?= e($u) ?>"><?= e($d['title']) ?></a><div class="small text-muted"><?= doc_vendor_owned($d) ? 'Uploaded by your agency' : 'Shared by ' . e(setting('company_name')) ?></div></td><td class="small"><?= e(doc_type_label($d)) ?></td><td class="small"><?= e(DOC_CATEGORIES[doc_category($d) ?? ''] ?? '—') ?></td>
              <td>v<?= (int) $d['version'] ?></td><td class="small"><?= fdate($d['sent_at'] ?: $d['created_at'], 'd M Y') ?></td><td><?= doc_status_pill($d) ?></td>
              <td class="text-end"><a class="btn btn-sm <?= doc_can($d, 'sign') ? 'btn-success' : 'btn-light border' ?>" href="<?= e($u) ?>"><?= doc_can($d, 'sign') ? 'Sign' : 'View' ?></a></td></tr>
          <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
      </div>
      <form class="card form-block" method="post" enctype="multipart/form-data" action="<?= e(url('vendor', ['a' => 'doc_upload'])) ?>"><?= csrf_field() ?>
        <div class="form-block-head"><span class="fb-icon"><i class="ri-upload-cloud-2-line"></i></span><div><h6>Upload a Word document</h6><small>e.g. your agency agreement draft or an NDA mark-up</small></div></div>
        <div class="form-block-body vstack gap-2">
          <select class="form-select form-select-sm" name="doc_type" required aria-label="Document type"><option value="">Document type…</option><?php foreach (DOC_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
          <select class="form-select form-select-sm" name="service_category" required aria-label="Service category"><?php foreach (DOC_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
          <input class="form-control form-control-sm" name="title" maxlength="190" placeholder="Title (optional)">
          <input class="form-control form-control-sm" type="file" name="file" accept=".docx,.doc" required>
          <div class="small text-muted">.docx or .doc, max <?= (int) setting('doc_max_mb', 10) ?> MB. Checked on upload; only your agency and our team can see it.</div>
          <button class="btn btn-primary btn-sm">Upload</button></div></form>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Submit a candidate ───────────── */
if ($a === 'submit' && is_post()) {
    authorize('vendor_submissions');
    $jobId = int_input('job_id');
    $job = vendor_can_see_job($vid, $jobId) ?? not_found();
    $data = [];
    $errors = [];
    foreach (SUBMISSION_FIELDS as $col => [$label, $required]) {
        $v = trim((string) ($_POST[$col] ?? ''));
        if ($v === '' && $required) $errors[] = "$label is required.";
        $data[$col] = $v === '' ? null : mb_substr($v, 0, $col === 'key_skills' ? 2000 : 190);
    }
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail is invalid.';
    if ($data['phone'] && strlen(phone_key($data['phone'])) < 10) $errors[] = 'Phone must have at least 10 digits.';
    $note = trim((string) ($_POST['vendor_note'] ?? ''));
    $clientId = (int) ($job['client_id'] ?? 0);
    $email = norm_email($data['email']);
    $phone = norm_phone($data['phone']);
    $back = url('vendor', ['a' => 'job', 'id' => $jobId]) . '#submit';
    // Already in this job's pipeline (any source) → always rejected. With ownership locks off, the older global rule applies.
    if (!$errors) {
        if (lock_on()) {
            [$match, $mp] = contact_match_sql($data['email'], $data['phone']);
            if (val("SELECT id FROM hrm_candidates WHERE job_id = ? AND $match", [$jobId, ...$mp])) $errors[] = 'This candidate is already in the pipeline for this job.';
        } elseif ($dup = submission_duplicate($jobId, (string) $data['email'], (string) $data['phone'], $vid)) {
            $errors[] = $dup;
        }
    }
    if ($errors) {
        $_SESSION['_old'] = $_POST;
        flash('danger', implode('<br>', array_map('e', $errors)));
        redirect($back);
    }
    $store = function () use (&$data): ?string {
        try {
            $data['resume'] = store_upload('resume', 'resumes');
            return $data['resume'] ? null : 'Upload the candidate\'s resume.';
        } catch (RuntimeException $ex) {
            return 'Resume: ' . $ex->getMessage();
        }
    };
    if (!lock_on()) {
        if ($err = $store()) { $_SESSION['_old'] = $_POST; flash('danger', e($err)); redirect($back); }
        vendor_submission_insert($vid, $job, $data, $note, (int) user()['id']);
        flash('success', e($data['name']) . ' submitted for <strong>' . e($job['title']) . '</strong>. You can follow the progress under My Submissions.');
        redirect(url('vendor', ['a' => 'job', 'id' => $jobId]));
    }
    // Company-specific ownership: checked and written while holding this company's claim lock (no two partners can win).
    try {
        $out = lock_guard($clientId, function () use ($vid, $job, $clientId, $email, $phone, &$data, $note, $store) {
            $claim = lock_claim($vid, $clientId, $email, $phone);
            [$owner] = vendor_owner($vid);
            $ctx = ['client_id' => $clientId, 'vendor_id' => $owner];
            if ($claim['result'] === 'blocked') {
                lock_event((int) $claim['lock']['id'], 'claim_blocked', $ctx + ['candidate_id' => $claim['lock']['candidate_id']], "Competing submission of {$data['name']} for {$job['title']} refused (held by vendor #{$claim['lock']['vendor_id']})");
                return ['blocked'];
            }
            if ($err = $store()) return ['error', $err];
            if ($claim['result'] === 'review') {
                $rid = review_create($vid, $job, $data, $note, $claim['match'], (int) user()['id']);
                return ['review', $rid];
            }
            $cid = vendor_submission_insert($vid, $job, $data, $note, (int) user()['id']);
            if ($claim['result'] === 'own') {
                lock_event((int) $claim['lock']['id'], 'claim_own', $ctx + ['candidate_id' => $cid], "Submitted for {$job['title']} under the existing lock");
                return ['own', $claim['lock']];
            }
            $lid = lock_create(['candidate_id' => $cid, 'email' => $email, 'phone' => $phone, 'client_id' => $clientId, 'job_id' => (int) $job['id'], 'vendor_id' => $vid]);
            return ['locked', lock_find($lid)];
        });
    } catch (RuntimeException $ex) {
        $out = ['error', $ex->getMessage()];
    }
    $company = client_label($clientId);
    switch ($out[0]) {
        case 'blocked':
            $_SESSION['_old'] = $_POST;
            flash('danger', '<strong>' . e($data['name']) . ' is already protected for ' . e($company) . '.</strong> Another partner submitted this candidate (same e-mail / mobile) for this company and the ownership period is still active, so a competing submission can\'t be made. The candidate can still be submitted for other companies.');
            redirect($back);
        case 'error':
            $_SESSION['_old'] = $_POST;
            flash('danger', e($out[1]));
            redirect($back);
        case 'review':
            flash('warning', '<strong>Sent for review.</strong> The e-mail or mobile of ' . e($data['name']) . ' matches a candidate already protected for ' . e($company) . ', but the other detail is different. The recruitment team will confirm whether this is a different person; you will get an e-mail with the decision (review #' . (int) $out[1] . ').');
            redirect(url('vendor', ['a' => 'locks']));
        case 'own':
            flash('success', e($data['name']) . ' submitted for <strong>' . e($job['title']) . '</strong> under your existing ownership for ' . e($company) . ' (' . e(lock_remaining($out[1])) . ').');
            redirect(url('vendor', ['a' => 'job', 'id' => $jobId]));
        default:
            flash('success', e($data['name']) . ' submitted for <strong>' . e($job['title']) . '</strong> and <strong>protected for ' . e($company) . '</strong> until ' . e(fdate($out[1]['expires_at'], 'd M Y')) . '. Follow it under Protected Candidates.');
            redirect(url('vendor', ['a' => 'job', 'id' => $jobId]));
    }
}

/* ───────────── One job ───────────── */
if ($a === 'job') {
    authorize('vendor_jobs');
    $job = one('SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE j.id = ?', [int_input('id')]);
    if (!$job || !vendor_can_see_job($vid, (int) $job['id'])) not_found();
    $mine = all("SELECT id, name, stage, created_at FROM hrm_candidates WHERE job_id = ? AND vendor_id IN ($myIn) ORDER BY id DESC", [$job['id']]);
    page_start($job['title'], ['Open Jobs' => url('vendor', ['a' => 'jobs']), $job['title']],
        can('vendor_submissions') ? '<a class="btn btn-primary" href="#submit"><i class="ri-user-add-line me-1"></i>Submit candidate</a>' : '');
    ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card"><div class="card-body">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach (['ri-map-pin-2-line' => $job['location'], 'ri-briefcase-4-line' => $job['employment_type'], 'ri-award-line' => $job['experience'], 'ri-money-rupee-circle-line' => $job['salary_range'], 'ri-group-line' => (int) $job['openings'] . ' opening(s)', 'ri-calendar-close-line' => $job['closing_date'] ? 'Closes ' . fdate($job['closing_date']) : null] as $icon => $val): if (!$val) continue; ?>
              <span class="tag tag-lg"><i class="<?= $icon ?> me-1"></i><?= e($val) ?></span>
            <?php endforeach; ?>
          </div>
          <?php if ($job['skills']): ?><div class="mb-3"><div class="small text-muted mb-1">Key skills</div><?= skill_chips($job['skills']) ?></div><?php endif; ?>
          <h6 class="fw-bold">Job description</h6>
          <div class="jd"><?= $job['description'] ? nl2br(e($job['description'])) : '<span class="text-muted">No description provided. Raise a ticket if you need more detail.</span>' ?></div>
        </div></div>

        <?php if (can('vendor_submissions')): ?>
        <form class="card" id="submit" method="post" enctype="multipart/form-data" action="<?= e(url('vendor', ['a' => 'submit'])) ?>">
          <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
          <div class="card-header-lite"><span><i class="ri-user-add-line text-primary me-1"></i>Submit a candidate</span><small class="text-muted"><span class="text-danger">*</span> required</small></div>
          <div class="card-body">
            <?php if (lock_on()): ?>
            <div class="alert alert-light border small"><i class="ri-lock-2-line text-primary me-1"></i><strong>Ownership protection:</strong> a candidate you submit is protected for <strong><?= e(client_label((int) ($job['client_id'] ?? 0))) ?></strong> for <?= lock_days() ?> days (matched on e-mail <em>and</em> mobile). Other partners can't submit the same person to this company while your protection is active; it continues while the client is still interviewing. Profiles already in this job's pipeline are rejected. Submit only candidates you have screened and who agreed to be represented.</div>
            <?php else: ?>
            <div class="alert alert-light border small"><i class="ri-shield-check-line text-primary me-1"></i><strong>Duplicate rule:</strong> profiles already in this job's pipeline, or submitted by another source in the last <?= (int) setting('vendor_dup_days', 90) ?> days, are rejected automatically. Submit only candidates you have screened and who have agreed to be represented.</div>
            <?php endif; ?>
            <div class="row g-3">
              <?php foreach (SUBMISSION_FIELDS as $col => [$label, $req]):
                  $f = ['label' => $label, 'required' => $req, 'col' => $col === 'key_skills' ? 'col-12' : 'col-md-4'];
                  if ($col === 'email') $f['type'] = 'email';
                  if ($col === 'phone') $f['type'] = 'tel';
                  if ($col === 'key_skills') { $f['type'] = 'textarea'; $f['rows'] = 2; $f['placeholder'] = 'Comma separated, e.g. Java, Spring Boot, AWS'; }
                  if ($col === 'work_exp') $f['placeholder'] = 'e.g. 4.5 years';
                  echo form_field($col, $f, old($col));
              endforeach; ?>
              <div class="col-md-6"><label class="form-label" for="f_resume">Resume (PDF / Word, max 5 MB) <span class="text-danger">*</span></label>
                <input class="form-control" type="file" id="f_resume" name="resume" accept=".pdf,.doc,.docx" required></div>
              <?= form_field('vendor_note', ['label' => 'Note to recruiter', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-md-6', 'placeholder' => 'Availability, reason for change, highlights…'], old('vendor_note')) ?>
            </div>
          </div>
          <div class="form-actions px-3 pb-3"><button class="btn btn-primary"><i class="ri-send-plane-2-line me-1"></i>Submit candidate</button></div>
        </form>
        <?php endif; ?>
      </div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">Your submissions for this job <span class="count-pill"><?= count($mine) ?></span></div>
          <?php foreach ($mine as $c): ?>
            <a class="list-row text-body" href="<?= url('vendor', ['a' => 'submission', 'id' => $c['id']]) ?>"><?= avatar($c['name'], null, 'sm') ?>
              <div class="grow"><div class="title"><?= e($c['name']) ?></div><div class="sub"><?= time_ago($c['created_at']) ?></div></div><?= stage_pill($c['stage']) ?></a>
          <?php endforeach; ?>
          <?php if (!$mine): ?><div class="card-body small text-muted">You haven't submitted anyone for this job yet.</div><?php endif; ?>
        </div>
        <div class="card"><div class="card-body small">
          <div class="fw-semibold mb-1"><i class="ri-information-line text-primary"></i> Questions about this requirement?</div>
          <p class="text-muted mb-2">Ask the recruitment team through a support ticket so the conversation is tracked.</p>
          <a class="btn btn-light border btn-sm w-100" href="<?= url('support', ['a' => 'create', 'category' => 'Vendor portal', 'subject' => 'Query on ' . $job['title']]) ?>"><i class="ri-question-answer-line me-1"></i>Ask a question</a>
        </div></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── One submission ───────────── */
if ($a === 'submission') {
    authorize('vendor_submissions');
    $c = one("SELECT c.id, c.name, c.email, c.phone, c.designation, c.current_employer, c.work_exp, c.key_skills, c.current_ctc, c.expected_ctc,
                     c.notice_period, c.current_location, c.stage, c.interview_at, c.resume, c.created_at, c.updated_at, j.title job, j.id job_id
                     , c.vendor_id, c.job_id cjob
              FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id = ? AND c.vendor_id IN ($myIn)", [int_input('id')]) ?? not_found();
    // Another partner now holds this person for the same company: the protected profile stays closed while that lock is active.
    if ($blk = lock_blocking_vendor($vid, $c + ['job_id' => $c['cjob']])) {
        lock_event((int) $blk['id'], 'profile_blocked', ['candidate_id' => $c['id'], 'client_id' => $blk['client_id'], 'vendor_id' => vendor_owner($vid)[0]], 'Vendor portal: protected profile not shown');
        page_start('Profile protected', ['My Submissions' => url('vendor', ['a' => 'submissions']), 'Protected']);
        echo '<div class="card">'; empty_state('ri-lock-2-line', 'This profile is protected for another partner', 'The candidate is currently under another partner\'s ownership for ' . client_label((int) $blk['client_id']) . '. Its details are hidden while that protection is active. You can still submit the candidate for other companies.', '<a class="btn btn-primary" href="' . url('vendor', ['a' => 'submissions']) . '">Back to my submissions</a>'); echo '</div>';
        page_end();
        return;
    }
    page_start($c['name'], ['My Submissions' => url('vendor', ['a' => 'submissions']), $c['name']]);
    ?>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><div class="small text-muted">Submitted for</div><strong><?= e($c['job'] ?? '—') ?></strong></div>
        <div class="text-end"><div class="small text-muted">Current status</div><strong><?= e(CANDIDATE_STAGE_LABELS[$c['stage']] ?? $c['stage']) ?></strong></div></div>
      <?= stage_tracker($c['stage']) ?>
      <?php if ($c['stage'] === 'Interview' && $c['interview_at']): ?><div class="alert alert-info small mt-3 mb-0"><i class="ri-calendar-event-line me-1"></i>Interview scheduled for <strong><?= fdate($c['interview_at'], 'D, d M Y · h:i A') ?></strong>. Please make sure the candidate is available.</div><?php endif; ?>
    </div></div>
    <div class="grid grid-3">
      <div class="card span-2"><div class="card-header-lite">Profile you submitted</div><div class="card-body"><dl class="detail-grid">
        <?php foreach (['email' => 'E-mail', 'phone' => 'Phone', 'designation' => 'Designation', 'current_employer' => 'Current employer', 'work_exp' => 'Experience',
                           'current_ctc' => 'Current CTC', 'expected_ctc' => 'Expected CTC', 'notice_period' => 'Notice period', 'current_location' => 'Location'] as $k => $l): ?>
          <div><dt><?= $l ?></dt><dd><?= e($c[$k] ?: '—') ?></dd></div>
        <?php endforeach; ?>
        <div class="span-all"><dt>Key skills</dt><dd><?= skill_chips($c['key_skills']) ?: '—' ?></dd></div>
      </dl></div></div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-body">
          <?php if ($c['resume']): ?><a class="btn btn-primary w-100" target="_blank" href="<?= e(file_url($c['resume'])) ?>"><i class="ri-file-text-line me-1"></i>Open resume</a><?php endif; ?>
          <div class="small text-muted mt-3">Submitted <?= fdate($c['created_at'], 'd M Y, h:i A') ?><?= $c['updated_at'] ? '<br>Last update ' . time_ago($c['updated_at']) : '' ?></div>
        </div></div>
        <div class="card"><div class="card-body small">Need to update this profile (new CTC, availability)? <a href="<?= url('support', ['a' => 'create', 'category' => 'Vendor portal', 'subject' => 'Update for ' . $c['name'] . ' (' . ($c['job'] ?? '') . ')']) ?>">Send an update to the recruiter</a>.</div></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── All submissions ───────────── */
if ($a === 'submissions') {
    authorize('vendor_submissions');
    $stage = in_array($_GET['stage'] ?? '', CANDIDATE_STAGES, true) ? $_GET['stage'] : '';
    $q = trim((string) input('q'));
    $where = "c.vendor_id IN ($myIn)";
    $params = [];
    if ($stage) { $where .= ' AND c.stage = ?'; $params[] = $stage; }
    if ($q !== '') { $where .= ' AND (c.name LIKE ? OR c.email LIKE ? OR j.title LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
    $rows = all("SELECT c.id, c.name, c.email, c.phone, c.job_id, c.vendor_id, c.stage, c.created_at, c.updated_at, j.title job, v.name agency FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id LEFT JOIN hrm_vendors v ON v.id = c.vendor_id WHERE $where ORDER BY c.id DESC LIMIT 300", $params);
    $counts = array_column(all("SELECT stage, COUNT(*) c FROM hrm_candidates WHERE vendor_id IN ($myIn) GROUP BY stage"), 'c', 'stage');
    // Ownership of each listed profile for its company (own lock with timer, or held by another partner).
    $ownLocks = [];
    if (lock_on()) foreach (all("SELECT * FROM hrm_candidate_locks WHERE status IN ('Active','Held','Converted') AND vendor_id = ?", [vendor_owner($vid)[0]]) as $l) $ownLocks[(int) $l['candidate_id']] = $l;
    page_start('My Submissions', ['My Submissions'], '<a class="btn btn-primary" href="' . url('vendor', ['a' => 'jobs']) . '"><i class="ri-user-add-line me-1"></i>Submit a candidate</a>');
    ?>
    <div class="card list-card">
      <nav class="list-tabs">
        <a class="<?= $stage === '' ? 'active' : '' ?>" href="<?= url('vendor', ['a' => 'submissions']) ?>">All <span><?= array_sum($counts) ?></span></a>
        <?php foreach (CANDIDATE_STAGES as $s): ?><a class="<?= $stage === $s ? 'active' : '' ?>" href="<?= url('vendor', ['a' => 'submissions', 'stage' => $s]) ?>"><?= e(CANDIDATE_STAGE_LABELS[$s]) ?> <span><?= (int) ($counts[$s] ?? 0) ?></span></a><?php endforeach; ?>
      </nav>
      <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="vendor"><input type="hidden" name="a" value="submissions">
        <?php if ($stage): ?><input type="hidden" name="stage" value="<?= e($stage) ?>"><?php endif; ?>
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, e-mail or job"></div>
        <span class="ms-auto small text-muted"><?= count($rows) ?> record(s)</span></form>
      <?php if (!$rows): empty_state('ri-send-plane-line', 'No submissions yet', 'Open a job and submit your first candidate.', '<a class="btn btn-primary" href="' . url('vendor', ['a' => 'jobs']) . '">View open jobs</a>'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Candidate</th><th>Job</th><th>Status</th><?= lock_on() ? '<th>Ownership</th>' : '' ?><th>Submitted</th><th>Last update</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="row-link" data-href="<?= e(url('vendor', ['a' => 'submission', 'id' => $r['id']])) ?>">
            <td><a class="person text-body" href="<?= e(url('vendor', ['a' => 'submission', 'id' => $r['id']])) ?>"><?= avatar($r['name'], null, 'sm') ?><span class="meta"><strong><?= e($r['name']) ?></strong><small><?= e($r['email']) ?></small></span></a></td>
            <td><?= e($r['job'] ?? '—') ?><?= count($myIds) > 1 && (int) $r['vendor_id'] !== $vid ? '<div class="small text-muted">via ' . e($r['agency']) . '</div>' : '' ?></td><td><?= stage_pill($r['stage']) ?></td>
            <?php if (lock_on()): ?><td><?php if (isset($ownLocks[(int) $r['id']])): ?><?= lock_timer($ownLocks[(int) $r['id']]) ?><?php elseif (lock_blocking_vendor($vid, $r)): ?><span class="pill pill-neutral"><i class="ri-lock-line me-1"></i>Held by another partner</span><?php else: ?><span class="small text-muted">—</span><?php endif; ?></td><?php endif; ?>
            <td class="small"><?= fdate($r['created_at']) ?></td><td class="small text-muted"><?= $r['updated_at'] ? time_ago($r['updated_at']) : '—' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Protected candidates (ownership locks of this partner and its sub-vendors) ───────────── */
if ($a === 'locks') {
    authorize('vendor_submissions');
    [$owner] = vendor_owner($vid);
    $mineOnly = vendor_owner($vid)[1] !== null ? ' AND (l.sub_vendor_id = ' . (int) $vid . ')' : ''; // a sub-vendor sees the locks it created
    $locks = lock_on() ? all("SELECT l.*, c.name candidate, c.stage, j.title job, sv.name sub_vendor FROM hrm_candidate_locks l LEFT JOIN hrm_candidates c ON c.id = l.candidate_id
                              LEFT JOIN hrm_jobs j ON j.id = l.job_id LEFT JOIN hrm_vendors sv ON sv.id = l.sub_vendor_id
                              WHERE l.vendor_id = ? $mineOnly AND (l.status IN ('Active','Held','Converted') OR l.released_at >= ?) ORDER BY FIELD(l.status,'Active','Held','Converted') DESC, l.expires_at",
        [$owner, date('Y-m-d H:i:s', time() - 90 * 86400)]) : [];
    $reviews = all('SELECT r.*, j.title job FROM hrm_ownership_reviews r LEFT JOIN hrm_jobs j ON j.id = r.job_id WHERE r.vendor_id = ? ' . ($mineOnly ? 'AND r.sub_vendor_id = ' . (int) $vid : '') . ' ORDER BY r.status = \'Pending\' DESC, r.id DESC LIMIT 30', [$owner]);
    $live = array_filter($locks, fn($l) => in_array($l['status'], LOCK_LIVE, true));
    $soon = array_filter($live, fn($l) => $l['status'] === 'Active' && strtotime($l['expires_at']) < time() + 7 * 86400);
    page_start('Protected Candidates', ['Protected Candidates']);
    ?>
    <div class="kpi-strip mb-3">
      <?= kpi('Protected now', count(array_filter($live, fn($l) => $l['status'] === 'Active')), 'ri-lock-2-line', '#16a34a') ?>
      <?= kpi('In client process', count(array_filter($live, fn($l) => $l['status'] === 'Held')), 'ri-pause-circle-line', '#0ea5e9') ?>
      <?= kpi('Expiring in 7 days', count($soon), 'ri-timer-flash-line', '#d97706') ?>
      <?= kpi('Hired (kept)', count(array_filter($live, fn($l) => $l['status'] === 'Converted')), 'ri-user-star-line', '#0077c0') ?>
    </div>
    <div class="alert alert-light border small d-flex gap-2"><i class="ri-information-line text-primary fs-5"></i><div>Each candidate you submit is protected <strong>for that company only</strong> for <?= lock_days() ?> days. While the client is still interviewing, the protection is held after the period ends; a hired candidate stays yours. The recruitment team can extend or release a protection, and you are e-mailed <?= (int) setting('lock_reminder_days', 3) ?> days before it ends.</div></div>
    <?php if ($reviews): ?>
    <div class="card mb-3"><div class="card-header-lite"><span><i class="ri-scales-3-line text-primary me-1"></i>Ownership reviews</span></div>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Candidate</th><th>Job</th><th>Why it needs review</th><th>Status</th><th>Sent</th></tr></thead><tbody>
      <?php foreach ($reviews as $r): $p = json_decode($r['payload'], true); ?>
        <tr><td><strong><?= e($p['data']['name'] ?? '—') ?></strong></td><td><?= e($r['job'] ?? '—') ?></td><td class="small text-muted"><?= e(ucfirst((string) $r['conflict'])) ?></td>
          <td><?= $r['status'] === 'Pending' ? '<span class="pill pill-warning">Under review</span>' : ($r['status'] === 'Approved' ? '<span class="pill pill-success">Accepted</span>' : '<span class="pill pill-danger">Not accepted</span>') ?></td>
          <td class="small"><?= time_ago($r['created_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <div class="card list-card">
      <?php if (!$locks): empty_state('ri-lock-2-line', lock_on() ? 'No protected candidates yet' : 'Ownership protection is turned off', lock_on() ? 'Candidates you submit are protected for the client company automatically.' : 'Ask the recruitment team for details.', '<a class="btn btn-primary" href="' . url('vendor', ['a' => 'jobs']) . '">View open jobs</a>'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle" data-grid="vendor-locks"><thead><tr><th>Candidate</th><th>Company</th><th>Job</th><th>Status</th><th>Time left</th><th>Protected since</th></tr></thead><tbody>
      <?php foreach ($locks as $l): ?>
        <tr<?= $l['candidate_id'] ? ' class="row-link" data-href="' . e(url('vendor', ['a' => 'submission', 'id' => $l['candidate_id']])) . '"' : '' ?>>
          <td><span class="person"><?= avatar($l['candidate'] ?? '?', null, 'sm') ?><span class="meta"><strong><?= e($l['candidate'] ?? '—') ?></strong><small><?= $l['stage'] ? e(CANDIDATE_STAGE_LABELS[$l['stage']] ?? $l['stage']) : '' ?><?= $l['sub_vendor'] ? ' · via ' . e($l['sub_vendor']) : '' ?></small></span></span></td>
          <td><?= e(client_label((int) $l['client_id'])) ?></td><td class="small"><?= e($l['job'] ?? '—') ?></td>
          <td><?= lock_status_pill($l['status']) ?><?= $l['extensions'] ? ' <small class="text-muted">+' . (int) $l['extensions'] . ' ext.</small>' : '' ?></td>
          <td><?= lock_timer($l) ?></td><td class="small"><?= fdate($l['locked_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Agency profile ───────────── */
if ($a === 'agency') {
    page_start('Agency Profile', ['Agency Profile']);
    ?>
    <div class="grid grid-2" style="max-width:980px">
      <div class="card card-body">
        <div class="person mb-3"><?= avatar($agency['name'], null, 'lg') ?><div class="meta"><strong class="fs-5"><?= e($agency['name']) ?></strong><small>Partner agency · <?= badge($agency['status']) ?></small></div></div>
        <dl class="detail-grid" style="grid-template-columns:1fr">
          <?php foreach (['contact_person' => 'Contact person', 'email' => 'E-mail', 'phone' => 'Phone', 'city' => 'City', 'specialization' => 'Specialisation', 'gst_no' => 'GSTIN'] as $k => $l): ?>
            <div><dt><?= $l ?></dt><dd><?= e($agency[$k] ?: '—') ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </div>
      <div class="card card-body">
        <h6 class="fw-bold mb-3"><i class="ri-file-shield-2-line text-primary me-1"></i>Agreement</h6>
        <dl class="detail-grid" style="grid-template-columns:1fr">
          <div><dt>Fee</dt><dd><?= $agency['fee_value'] === null ? '—' : ($agency['fee_type'] === 'Fixed' ? e(money($agency['fee_value'])) . ' per hire' : e((float) $agency['fee_value']) . '% of annual CTC') ?></dd></div>
          <div><dt>Replacement period</dt><dd><?= $agency['replacement_days'] ? (int) $agency['replacement_days'] . ' days' : '—' ?></dd></div>
          <div><dt>Payment terms</dt><dd><?= e($agency['payment_terms'] ?: '—') ?></dd></div>
          <div><dt>Valid until</dt><dd><?= fdate($agency['agreement_until']) ?></dd></div>
        </dl>
        <p class="small text-muted mt-3 mb-0">To change these details, <a href="<?= url('support', ['a' => 'create', 'category' => 'Vendor portal', 'subject' => 'Update agency details']) ?>">raise a ticket</a>.</p>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Open jobs (default) ───────────── */
authorize('vendor_jobs');
[$w, $p] = vendor_jobs_where($vid);
$q = trim((string) input('q'));
if ($q !== '') { $w .= ' AND (j.title LIKE ? OR j.skills LIKE ? OR j.location LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
$jobs = all("SELECT j.*, d.name dept, (SELECT COUNT(*) FROM hrm_candidates c WHERE c.job_id = j.id AND c.vendor_id = ?) mine
             FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE $w ORDER BY j.created_at DESC", [$vid, ...$p]);
page_start('Open Jobs', ['Open Jobs']);
?>
<form class="card card-body mb-3 flex-row flex-wrap gap-2 align-items-center" method="get" action="<?= base_path() ?>/dashboard/">
  <input type="hidden" name="r" value="vendor"><input type="hidden" name="a" value="jobs">
  <div class="search-box flex-grow-1"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by title, skill or location"></div>
  <span class="small text-muted text-nowrap"><?= count($jobs) ?> open requirement(s)</span>
</form>
<?php if (!$jobs): ?>
  <div class="card"><?php empty_state('ri-briefcase-4-line', $q ? 'No jobs match your search' : 'No requirements shared right now', 'New requirements shared with your agency will appear here.'); ?></div>
<?php else: ?>
  <div class="job-grid">
    <?php foreach ($jobs as $j) echo job_card($j, url('vendor', ['a' => 'job', 'id' => $j['id']]),
        '<div class="jc-foot"><span class="small text-muted">' . ($j['closing_date'] ? 'Closes ' . fdate($j['closing_date']) : 'Open until filled') . '</span><span class="tag">' . (int) $j['mine'] . ' submitted by you</span></div>'); ?>
  </div>
<?php endif;
page_end();
