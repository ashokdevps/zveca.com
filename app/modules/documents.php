<?php
/**
 * Agreements & Documents (Super Admin, Admin = client services, Business Development = assigned clients only).
 *   index            all documents in scope: filters by type, service category, status, client, vendor, date range
 *   new / create     upload a Word document, start from a template, or a blank document — for a client or a vendor
 *   view (id)        workspace: editor / preview, details, actions, versions, signatures, access, audit trail
 *   save, meta, review, approve, sign_internal, send, cancel, revoke, version, convert, external (POST)
 *   download (id)    original file or a .docx of the current text (permission + audit)
 * Every action re-checks doc_can() on the server.
 */

$role = doc_role();
if (!in_array($role, ['admin', 'staff', 'bd'], true)) authorize('__never__');
$a = $_GET['a'] ?? 'index';
$back = fn(int $id, string $type, string $msg) => [flash($type, $msg), redirect(url('documents', ['a' => 'view', 'id' => $id]))];
[$scopeW, $scopeP] = doc_staff_scope('d');

/** Clients / vendors / engagements the user may attach documents to. */
function doc_party_options(): array
{
    if (doc_role() === 'bd') {
        $u = (int) user()['id'];
        $clients = array_column(all('SELECT id, name FROM hrm_clients WHERE account_manager_id = ? OR id IN (SELECT client_id FROM hrm_engagements WHERE owner_id = ?) ORDER BY name', [$u, $u]), 'name', 'id');
        $engs = all("SELECT e.id, e.ref, e.title, e.client_id, e.service FROM hrm_engagements e WHERE e.status <> 'Draft' AND (e.owner_id = ? OR e.client_id IN (SELECT id FROM hrm_clients WHERE account_manager_id = ?)) ORDER BY e.id DESC", [$u, $u]);
        return [$clients, [], $engs];
    }
    return [relation_options(['table' => 'hrm_clients']), relation_options(['table' => 'hrm_vendors', 'where' => "status = 'Active'"]),
        all("SELECT e.id, e.ref, e.title, e.client_id, e.service FROM hrm_engagements e WHERE e.status <> 'Draft' ORDER BY e.id DESC LIMIT 500")];
}

/* ───────────── Create ───────────── */
if ($a === 'create' && is_post()) {
    verify_csrf();
    [$clients, $vendors, $engs] = doc_party_options();
    $party = input('party') === 'vendor' ? 'vendor' : 'client';
    $clientId = $party === 'client' ? int_input('client_id') : 0;
    $vendorId = $party === 'vendor' ? int_input('vendor_id') : 0;
    $engId = int_input('engagement_id');
    $eng = $engId ? one('SELECT * FROM hrm_engagements WHERE id = ?', [$engId]) : null;
    $mode = in_array(input('mode'), ['upload', 'template', 'blank'], true) ? input('mode') : 'upload';
    $old = fn() => [$_SESSION['_old'] = $_POST, redirect(url('documents', ['a' => 'new']))];
    try {
        if ($party === 'client' && !isset($clients[$clientId])) throw new RuntimeException('Choose a client you are allowed to work with.');
        if ($party === 'vendor' && !isset($vendors[$vendorId])) throw new RuntimeException('Choose a vendor.');
        if ($eng && (int) $eng['client_id'] !== $clientId) throw new RuntimeException('The engagement belongs to a different client.');
        if ($eng && !in_array($engId, array_map('intval', array_column($engs, 'id')), true)) throw new RuntimeException('You are not assigned to that engagement.');
        $type = (string) input('doc_type');
        $title = mb_substr(trim((string) input('title')), 0, 190) ?: (DOC_TYPES[$type] ?? 'Document') . ($eng ? ' — ' . eng_title(eng_find($engId)) : '');
        $base = ['title' => $title, 'doc_type' => $type, 'service_category' => (string) input('service_category'), 'client_id' => $clientId ?: null, 'vendor_id' => $vendorId ?: null, 'engagement_id' => $engId ?: null];
        if ($mode === 'upload') {
            [$path, $ext, $html, $orig, $sha] = doc_store_word('file');
            $id = doc_create($base + ['file' => $path, 'original_name' => $orig, 'file_format' => $ext, 'file_hash' => $sha, 'source' => 'upload', 'content' => $html !== '' ? $html : null], 'uploaded', $orig . ' (' . strtoupper($ext) . ')');
            if ($ext === 'doc') flash('warning', 'Legacy .doc uploaded. It is kept unchanged; use <strong>Convert to .docx</strong> to edit it in the browser (the original stays as version 1).');
        } elseif ($mode === 'template') {
            $tpl = isset(DOC_TYPE_TEMPLATES[$type]) ? eng_template(DOC_TYPE_TEMPLATES[$type]) : null;
            if (!$tpl) throw new RuntimeException('There is no template for this document type. Upload a Word document or start blank.');
            $html = $eng ? eng_render($tpl['body'], eng_find($engId)) : policy_html($tpl['body']);
            $id = doc_create($base + ['content' => doc_clean_html($html), 'source' => 'template', 'template_version' => $tpl['version']], 'created', 'From template "' . $tpl['name'] . '" v' . $tpl['version']);
        } else {
            $id = doc_create($base + ['content' => '<h2>' . e($title) . '</h2><p></p>', 'source' => 'blank'], 'created', 'Blank document');
        }
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
        $old();
    }
    flash('success', 'Document created as <strong>Draft</strong> version 1.');
    redirect(url('documents', ['a' => 'view', 'id' => $id]));
}

if ($a === 'new') {
    [$clients, $vendors, $engs] = doc_party_options();
    $pre = ['client_id' => int_input('client_id'), 'engagement_id' => int_input('engagement_id'), 'vendor_id' => int_input('vendor_id')];
    if ($pre['engagement_id'] && ($pe = one('SELECT client_id, service FROM hrm_engagements WHERE id = ?', [$pre['engagement_id']]))) { $pre['client_id'] = (int) $pe['client_id']; $pre['category'] = $pe['service'] === 'hr' ? 'recruitment' : 'it_dev'; }
    $o = fn($k, $d = '') => old($k, $pre[$k] ?? $d);
    page_start('New document', ['Agreements & Documents' => url('documents'), 'New']);
    ?>
    <form class="card form-block" method="post" enctype="multipart/form-data" action="<?= e(url('documents', ['a' => 'create'])) ?>" style="max-width:920px"><?= csrf_field() ?>
      <div class="form-block-head"><span class="fb-icon"><i class="ri-file-add-line"></i></span><div><h6>Document</h6><small>Type, service category and who it is for. Everything starts as a Draft.</small></div></div>
      <div class="form-block-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="doc_type">Document type <span class="text-danger">*</span></label><select class="form-select" id="doc_type" name="doc_type" required><option value="">Choose…</option><?php foreach (DOC_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $o('doc_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="service_category">Service category <span class="text-danger">*</span></label><select class="form-select" id="service_category" name="service_category" required><option value="">Choose…</option><?php foreach (DOC_CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= $o('service_category', $pre['category'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="title">Title</label><input class="form-control" id="title" name="title" maxlength="190" value="<?= e($o('title')) ?>" placeholder="Blank = document type + engagement"></div>
        <div class="col-md-3"><label class="form-label">Document for</label><div class="d-flex gap-3 pt-1"><label><input type="radio" name="party" value="client" <?= $o('party', $pre['vendor_id'] ? 'vendor' : 'client') === 'client' ? 'checked' : '' ?> data-party> Client</label><?php if ($vendors): ?><label><input type="radio" name="party" value="vendor" <?= $o('party', $pre['vendor_id'] ? 'vendor' : 'client') === 'vendor' ? 'checked' : '' ?> data-party> Vendor</label><?php endif; ?></div></div>
        <div class="col-md-4" data-for-party="client"><label class="form-label" for="client_id">Client</label><select class="form-select" id="client_id" name="client_id"><option value="">Choose…</option><?php foreach ($clients as $k => $l): ?><option value="<?= $k ?>" <?= (int) $o('client_id') === (int) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5" data-for-party="client"><label class="form-label" for="engagement_id">Engagement <span class="text-muted small">(optional)</span></label><select class="form-select" id="engagement_id" name="engagement_id"><option value="">Company-level document</option><?php foreach ($engs as $en): ?><option value="<?= $en['id'] ?>" data-client="<?= $en['client_id'] ?>" <?= (int) $o('engagement_id') === (int) $en['id'] ? 'selected' : '' ?>><?= e($en['ref'] . ' · ' . ($en['title'] ?: strtoupper($en['service']))) ?></option><?php endforeach; ?></select></div>
        <?php if ($vendors): ?><div class="col-md-9" data-for-party="vendor" hidden><label class="form-label" for="vendor_id">Vendor</label><select class="form-select" id="vendor_id" name="vendor_id"><option value="">Choose…</option><?php foreach ($vendors as $k => $l): ?><option value="<?= $k ?>" <?= (int) $o('vendor_id') === (int) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-12"><label class="form-label">Start from</label>
          <div class="doc-modes">
            <label><input type="radio" name="mode" value="upload" checked> <span><i class="ri-upload-cloud-2-line"></i><strong>Upload Word document</strong><small>.docx (editable) or .doc · max <?= (int) setting('doc_max_mb', 10) ?> MB</small></span></label>
            <label><input type="radio" name="mode" value="template"> <span><i class="ri-file-copy-2-line"></i><strong>Company template</strong><small>NDA, recruitment, MSA, SOW, commercial terms</small></span></label>
            <label><input type="radio" name="mode" value="blank"> <span><i class="ri-file-line"></i><strong>Blank document</strong><small>Write it in the editor</small></span></label>
          </div></div>
        <div class="col-12" data-mode-for="upload"><label class="form-label" for="file">Word document</label><input class="form-control" type="file" id="file" name="file" accept=".docx,.doc,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword">
          <div class="form-text">The file is checked on the server (real Word package, no macros) and stored privately.</div></div>
      </div></div>
      <div class="form-actions px-3 pb-3"><a class="btn btn-light border ms-auto" href="<?= url('documents') ?>">Cancel</a><button class="btn btn-primary"><i class="ri-check-line me-1"></i>Create draft</button></div>
    </form>
    <script>
    (function () {
      var f = document.currentScript.previousElementSibling;
      function sync() {
        var party = (f.querySelector('[name=party]:checked') || {}).value || 'client', mode = f.querySelector('[name=mode]:checked').value, cl = f.querySelector('#client_id').value;
        f.querySelectorAll('[data-for-party]').forEach(function (el) { el.hidden = el.getAttribute('data-for-party') !== party; });
        f.querySelectorAll('[data-mode-for]').forEach(function (el) { el.hidden = el.getAttribute('data-mode-for') !== mode; el.querySelectorAll('input').forEach(function (i) { i.required = !el.hidden; }); });
        f.querySelectorAll('#engagement_id option[data-client]').forEach(function (o) { o.hidden = cl && o.getAttribute('data-client') !== cl; });
      }
      f.addEventListener('change', sync); sync();
    })();
    </script>
    <?php
    page_end();
    return;
}

/* ───────────── Download (original or .docx of the current text) ───────────── */
if ($a === 'download') {
    $d = doc_find(int_input('id')) ?? not_found();
    doc_require($d, 'download');
    $safe = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $d['title']), '-') . '-v' . $d['version'];
    if (input('format') === 'original' && $d['file'] && is_file(STORAGE_DIR . '/uploads/' . $d['file'])) {
        doc_event((int) $d['id'], 'downloaded', 'Original ' . strtoupper((string) $d['file_format']));
        $ext = strtolower(pathinfo($d['file'], PATHINFO_EXTENSION));
        header('Content-Type: ' . (['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safe . '.' . $ext . '"');
        header('Cache-Control: private, no-store');
        readfile(STORAGE_DIR . '/uploads/' . $d['file']);
        exit;
    }
    if ($d['content'] === null) not_found();
    doc_event((int) $d['id'], 'downloaded', '.docx of version ' . $d['version']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $safe . '.docx"');
    header('Cache-Control: private, no-store');
    echo html_to_docx((string) $d['content'], '');
    exit;
}

/* ───────────── Actions on one document ───────────── */
if (is_post() && in_array($a, ['save', 'meta', 'review', 'approve', 'sign_internal', 'send', 'cancel', 'revoke', 'version', 'convert', 'external'], true)) {
    verify_csrf();
    $d = doc_find(int_input('id')) ?? not_found();
    $id = (int) $d['id'];
    try {
        switch ($a) {
            case 'save':
                doc_require($d, 'edit');
                if ($d['file_format'] === 'doc' && $d['source'] === 'upload') throw new RuntimeException('Convert the .doc to .docx before editing.');
                $nid = doc_save_content($d, (string) ($_POST['content'] ?? ''), mb_substr(trim((string) input('note')), 0, 200));
                $back($nid, 'success', $nid !== $id ? 'Saved as a <strong>new version</strong>: the version that was sent can no longer be signed.' : 'Document saved.');
            case 'meta':
                doc_require($d, 'meta');
                $type = (string) input('doc_type');
                $cat = (string) input('service_category');
                if (!isset(DOC_TYPES[$type]) || !isset(DOC_CATEGORIES[$cat])) throw new RuntimeException('Choose a valid type and service category.');
                $title = mb_substr(trim((string) input('title')), 0, 190) ?: $d['title'];
                update('hrm_client_documents', ['title' => $title, 'doc_type' => $type, 'service_category' => $cat, 'vendor_editable' => !empty($_POST['vendor_editable']) && $d['vendor_id'] ? 1 : 0,
                    'last_modified_by' => user()['id'], 'updated_at' => date('Y-m-d H:i:s')], $id);
                doc_event($id, 'meta', "Title: $title · " . DOC_TYPES[$type] . ' · ' . DOC_CATEGORIES[$cat] . ($d['vendor_id'] ? ' · vendor may edit: ' . (!empty($_POST['vendor_editable']) ? 'yes' : 'no') : ''));
                $back($id, 'success', 'Details saved.');
            case 'review':
                doc_require($d, 'review');
                update('hrm_client_documents', ['status' => 'In Review', 'updated_at' => date('Y-m-d H:i:s')], $id);
                doc_event($id, 'review', (string) input('note'));
                doc_notify_once("docreview:$id:{$d['version']}", [setting('eng_email') ?: setting('company_email')], 'Document for review: ' . $d['title'], user()['username'] . ' submitted "' . $d['title'] . '" (version ' . $d['version'] . ') for internal review: ' . site_origin() . url('documents', ['a' => 'view', 'id' => $id]));
                $back($id, 'success', 'Submitted for internal review.');
            case 'approve':
                doc_require($d, 'approve');
                update('hrm_client_documents', ['status' => 'Approved Internally', 'approved_by' => user()['id'], 'approved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], $id);
                doc_event($id, 'approved', (string) input('note'));
                $back($id, 'success', 'Approved internally. It can now be sent for signature.');
            case 'sign_internal':
                doc_require($d, 'sign_internal');
                $name = trim((string) input('signed_name'));
                $desig = trim((string) input('signer_designation'));
                if (empty($_POST['sign_consent'])) throw new RuntimeException('Confirm that you are authorised to sign for the company.');
                if (mb_strlen($name) < 3 || $desig === '') throw new RuntimeException('Enter the signatory\'s full name and designation.');
                if (val("SELECT 1 FROM hrm_client_signatures WHERE document_id = ? AND party = 'company'", [$id])) throw new RuntimeException('This version already carries the company signature.');
                if ($d['content'] === null) throw new RuntimeException('There is no text to sign.');
                if (!hash_equals((string) $d['content_hash'], hash('sha256', (string) $d['content']))) q('UPDATE hrm_client_documents SET content_hash = ? WHERE id = ?', [hash('sha256', (string) $d['content']), $id]);
                $d = doc_find($id);
                $sig = onb_store_signature((string) input('signature'), $id, 'company', 'clientdocs', 'isig');
                doc_store_sig_record($d, 'company', $name, $desig, (string) user()['email'], 'internal_draw', $sig);
                doc_event($id, 'signed_internal', "$name ($desig) · SHA-256 " . substr((string) $d['content_hash'], 0, 16) . '…');
                if (in_array($d['status'], ['Partially Signed'], true)) doc_after_party_sign(doc_find($id));
                $back($id, 'success', 'Company signature attached to version ' . $d['version'] . '. Editing the text now creates a new version.');
            case 'send':
                doc_require($d, 'send');
                if (val("SELECT 1 FROM hrm_client_signatures WHERE document_id = ? AND party = 'company'", [$id]) && $d['content_hash'] !== hash('sha256', (string) $d['content'])) throw new RuntimeException('The text changed after the company signature. Create a new version.');
                doc_send($d, int_input('recipient'), int_input('days', (int) setting('doc_sign_days', 14)), !empty($_POST['allow_download']), !empty($_POST['allow_changes']), !empty($_POST['countersign']));
                $back($id, 'success', 'Sent for signature. The recipient has been e-mailed a secure link.');
            case 'cancel':
                doc_require($d, 'cancel');
                $note = trim((string) input('note'));
                if (mb_strlen($note) < 3) throw new RuntimeException('Give a reason for cancelling.');
                update('hrm_client_documents', ['status' => 'Cancelled', 'cancelled_by' => user()['id'], 'cancelled_at' => date('Y-m-d H:i:s'), 'execution_note' => mb_substr($note, 0, 500), 'updated_at' => date('Y-m-d H:i:s')], $id);
                q('UPDATE hrm_document_access SET revoked_at = NOW(), revoked_by = ? WHERE document_id = ? AND revoked_at IS NULL', [user()['id'], $id]);
                doc_event($id, 'cancelled', $note);
                if ($d['recipient_email'] && $d['sent_at']) doc_notify_once("doccancel:$id", [$d['recipient_email']], 'Cancelled: ' . $d['title'], 'The signing request for "' . $d['title'] . '" (version ' . $d['version'] . ') has been cancelled. You do not need to sign it.');
                $back($id, 'success', 'Document cancelled and all access revoked.');
            case 'revoke':
                doc_require($d, 'revoke');
                $acc = one('SELECT * FROM hrm_document_access WHERE id = ? AND document_id = ? AND revoked_at IS NULL', [int_input('access_id'), $id]) ?? not_found();
                q('UPDATE hrm_document_access SET revoked_at = NOW(), revoked_by = ? WHERE document_id = ? AND revoked_at IS NULL AND COALESCE(client_id, 0) = ? AND COALESCE(vendor_id, 0) = ?', [user()['id'], $id, (int) $acc['client_id'], (int) $acc['vendor_id']]);
                doc_event($id, 'revoked', 'Access withdrawn from ' . ($acc['client_id'] ? 'client' : 'vendor') . ' (login #' . (int) $acc['login_id'] . ')');
                $back($id, 'success', 'Access revoked. The recipient can no longer open or sign this document.');
            case 'version':
                doc_require($d, 'version');
                [$path, $ext, $html, $orig, $sha] = doc_store_word('file');
                $nid = doc_new_version($d, ['file' => $path, 'original_name' => $orig, 'file_format' => $ext, 'file_hash' => $sha, 'source' => 'upload', 'content' => $html !== '' ? $html : null], 'Revised Word document uploaded: ' . $orig);
                doc_event($nid, 'uploaded', $orig);
                $back($nid, 'success', 'Revised document uploaded as version ' . ((int) $d['version'] + 1) . '. Earlier versions are kept.');
            case 'convert':
                doc_require($d, 'version');
                if ($d['file_format'] !== 'doc' || $d['content'] === null) throw new RuntimeException('Only .doc uploads with readable text can be converted.');
                $bin = html_to_docx((string) $d['content'], '');
                $path = 'agreements/' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.docx';
                if (!is_dir(STORAGE_DIR . '/uploads/agreements')) mkdir(STORAGE_DIR . '/uploads/agreements', 0755, true);
                file_put_contents(STORAGE_DIR . '/uploads/' . $path, $bin);
                $nid = doc_new_version($d, ['file' => $path, 'original_name' => preg_replace('/\.doc$/i', '.docx', (string) $d['original_name']), 'file_format' => 'docx', 'file_hash' => hash('sha256', $bin), 'source' => 'converted'], 'Converted from .doc (text and paragraphs; check formatting)');
                doc_event($nid, 'converted', 'From version ' . $d['version'] . ' (.doc kept unchanged)');
                $back($nid, 'success', 'Converted to an editable .docx as a new version. The original .doc stays unchanged in version ' . $d['version'] . '. Check the formatting before sending.');
            case 'external':
                doc_require($d, 'cancel');
                $method = in_array(input('method'), ['dsc', 'aadhaar_esign', 'wet_ink', 'notarised'], true) ? input('method') : 'dsc';
                $signer = trim((string) input('signer_name'));
                $ref = trim((string) input('reference'));
                if (mb_strlen($signer) < 3 || mb_strlen($ref) < 3) throw new RuntimeException('Enter the signer and the provider / stamp reference.');
                $file = store_upload('executed', 'agreements', 15);
                if (!$file || !str_ends_with($file, '.pdf')) throw new RuntimeException('Upload the executed copy as a PDF.');
                doc_store_sig_record($d, $d['client_id'] ? 'client' : 'vendor', $signer, (string) input('signer_designation'), '', 'external', $file);
                $labels = ['dsc' => 'Certificate-based digital signature (DSC)', 'aadhaar_esign' => 'Aadhaar eSign', 'wet_ink' => 'Wet-ink signature', 'notarised' => 'Notarised / e-stamped execution'];
                update('hrm_client_documents', ['status' => 'Signed', 'signed_at' => date('Y-m-d H:i:s'), 'execution_note' => mb_substr($labels[$method] . ': ' . $ref, 0, 500), 'updated_at' => date('Y-m-d H:i:s')], $id);
                doc_event($id, 'external', $labels[$method] . " · $signer · $ref · executed PDF uploaded");
                $back($id, 'success', 'Executed copy recorded (' . e($labels[$method]) . ').');
        }
    } catch (RuntimeException $ex) {
        $back($id, 'danger', e($ex->getMessage()));
    }
}

/* ───────────── Workspace ───────────── */
if ($a === 'view') {
    $d = doc_find(int_input('id')) ?? not_found();
    doc_require($d, 'view');
    $canEdit = doc_can($d, 'edit') && !($d['file_format'] === 'doc' && $d['source'] === 'upload');
    // Version chain: walk supersedes / superseded_by links (stable ids, not titles).
    $chain = [];
    $first = $d;
    while ($first['supersedes_id'] && ($p = one('SELECT * FROM hrm_client_documents WHERE id = ?', [$first['supersedes_id']]))) $first = $p;
    for ($x = $first; $x; $x = $x['superseded_by'] ? one('SELECT * FROM hrm_client_documents WHERE id = ?', [$x['superseded_by']]) : null) { $chain[] = $x; if (count($chain) > 50) break; }
    $sigs = eng_signatures((int) $d['id']);
    $events = all('SELECT ev.*, l.username FROM hrm_document_events ev LEFT JOIN it_login l ON l.id = ev.login_id WHERE ev.document_id IN (' . implode(',', array_map(fn($x) => (int) $x['id'], $chain)) . ') ORDER BY ev.id DESC LIMIT 100');
    $access = all('SELECT a.*, l.username, l.email FROM hrm_document_access a LEFT JOIN it_login l ON l.id = a.login_id WHERE a.document_id = ? ORDER BY a.id DESC', [$d['id']]);
    $recipients = doc_recipients($d);
    $act = fn(string $k) => doc_can($d, $k);
    $post = fn(string $act, string $label, string $cls, string $confirm = '', string $prompt = '', array $f = []) => str_replace('<button', '<button' . ($prompt ? ' data-prompt="' . e($prompt) . '"' : ''), post_button(url('documents', ['a' => $act]), $label, $cls, $confirm, ['id' => $d['id']] + $f));
    $hdr = [];
    if ($d['content'] !== null || $d['file']) $hdr[] = '<div class="dropdown"><button class="btn btn-light border" data-bs-toggle="dropdown"><i class="ri-download-2-line me-1"></i>Download</button><div class="dropdown-menu dropdown-menu-end">'
        . ($d['content'] !== null ? '<a class="dropdown-item" href="' . e(url('documents', ['a' => 'download', 'id' => $d['id']])) . '">Word (.docx) of this version</a>' : '')
        . ($d['file'] ? '<a class="dropdown-item" href="' . e(url('documents', ['a' => 'download', 'id' => $d['id'], 'format' => 'original'])) . '">Original upload (' . e(strtoupper((string) $d['file_format'])) . ')</a>' : '')
        . '<button class="dropdown-item" onclick="window.print()">Print / Save PDF</button></div></div>';
    page_start($d['title'], ['Agreements & Documents' => url('documents'), 'Version ' . $d['version']], implode('', $hdr));
    ?>
    <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
      <span class="eng-svc-icon sm"><i class="<?= $d['kind'] === 'offer_letter' ? 'ri-mail-star-line' : 'ri-file-shield-2-line' ?>"></i></span>
      <div class="grow"><div class="small text-muted"><?= e(doc_type_label($d)) ?> · <?= e(DOC_CATEGORIES[doc_category($d) ?? ''] ?? '—') ?> · Version <?= (int) $d['version'] ?><?= $d['is_current'] ? ' (current)' : '' ?></div>
        <h5 class="fw-bold m-0"><?= e($d['title']) ?> <?= doc_status_pill($d) ?></h5>
        <div class="small"><?= $d['client_id'] ? '<i class="ri-building-4-line"></i> ' . e($d['client_name']) : '<i class="ri-team-fill"></i> Vendor: ' . e($d['vendor_name']) ?>
          <?= $d['engagement_id'] ? ' · <a href="' . e(url('engagements', ['a' => 'view', 'id' => $d['engagement_id'], 'tab' => 'proposal'])) . '">' . e($d['ref']) . '</a>' : '' ?>
          · created by <?= e($d['creator'] ?? 'system') ?> <?= fdate($d['created_at'], 'd M Y, h:i A') ?><?= $d['modifier'] ? ' · last edit ' . e($d['modifier']) . ' ' . time_ago($d['updated_at']) : '' ?></div></div>
      <?php if (!$d['is_current'] && $d['superseded_by']): ?><a class="btn btn-sm btn-warning" href="<?= e(url('documents', ['a' => 'view', 'id' => $d['superseded_by']])) ?>">Open the current version</a><?php endif; ?>
    </div>
    <?php if (in_array($d['status'], ['Declined', 'Change Requested'], true) && $d['review_note']): ?><div class="alert alert-warning small mx-3"><strong><?= e($d['status']) ?> by the recipient:</strong> <?= e($d['review_note']) ?> — edit and send again (a new version is created automatically when needed).</div><?php endif; ?>
    </div>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php if ($canEdit): ?>
          <form class="card" method="post" action="<?= e(url('documents', ['a' => 'save'])) ?>" data-doc-editor><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <div class="doc-toolbar" role="toolbar" aria-label="Formatting">
              <?php foreach ([['bold', 'ri-bold', 'Bold'], ['italic', 'ri-italic', 'Italic'], ['underline', 'ri-underline', 'Underline'], ['h2', 'ri-h-2', 'Heading'], ['h3', 'ri-h-3', 'Sub-heading'], ['p', 'ri-text', 'Paragraph'],
                             ['insertUnorderedList', 'ri-list-unordered', 'Bullets'], ['insertOrderedList', 'ri-list-ordered', 'Numbering'], ['table', 'ri-table-line', 'Insert table'], ['undo', 'ri-arrow-go-back-line', 'Undo'], ['redo', 'ri-arrow-go-forward-line', 'Redo']] as [$cmd, $ic, $t]): ?>
                <button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $t ?>" aria-label="<?= $t ?>"><i class="<?= $ic ?>"></i></button>
              <?php endforeach; ?>
              <span class="ms-auto small text-muted" data-doc-state>Saved</span>
            </div>
            <div class="doc-page"><div class="doc-edit policy-body" contenteditable="true" data-doc-body><?= $d['content'] ?></div></div>
            <textarea name="content" hidden></textarea>
            <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center"><input class="form-control form-control-sm" name="note" maxlength="200" placeholder="What changed? (audit note, optional)" style="max-width:360px">
              <button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save<?= in_array($d['status'], ['Awaiting Signature', 'Partially Signed', 'Expired'], true) || ($d['sent_at'] && in_array($d['status'], ['Declined', 'Change Requested'], true)) ? ' as new version' : '' ?></button></div>
          </form>
        <?php else: ?>
          <?php if ($d['file_format'] === 'doc' && $d['source'] === 'upload'): ?><div class="alert alert-info small mb-0"><i class="ri-information-line me-1"></i>Legacy .doc: shown as a text preview and kept unchanged. <?= $act('version') ? $post('convert', 'Convert to .docx to edit', 'btn btn-sm btn-primary ms-2', 'Create an editable .docx as a new version? The original .doc stays unchanged.') : '' ?></div><?php endif; ?>
          <div class="card payslip m-0"><div class="card-body p-4 p-md-5"><?= $d['content'] !== null ? eng_doc_letter($d, eng_certificate($d)) : '<p class="text-muted">No preview available for this file. Download the original.</p>' ?></div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite"><i class="ri-history-line me-1 text-primary"></i>Audit trail</div><div class="card-body"><ol class="lock-timeline">
          <?php foreach ($events as $ev): ?><li class="ev-<?= e($ev['event']) ?>"><strong><?= e(DOC_EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></strong><?= $ev['detail'] ? '<div class="small">' . e($ev['detail']) . '</div>' : '' ?>
            <small class="text-muted"><?= fdate($ev['created_at'], 'd M Y, h:i A') ?> · <?= e($ev['username'] ?? ucfirst($ev['party'])) ?> (<?= e($ev['party']) ?>)</small></li><?php endforeach; ?>
          <?php if (!$events): ?><li class="text-muted small">No events yet.</li><?php endif; ?></ol></div></div>
      </div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">Actions</div><div class="card-body vstack gap-2">
          <?php if ($act('review')): ?><?= $post('review', '<i class="ri-eye-line me-1"></i>Submit for internal review', 'btn btn-light border w-100') ?><?php endif; ?>
          <?php if ($act('approve')): ?><?= $post('approve', '<i class="ri-check-double-line me-1"></i>Approve internally', 'btn btn-success w-100', 'Approve this version for sending?') ?><?php endif; ?>
          <?php if ($act('send') && $recipients): ?>
            <details class="doc-panel"<?= $d['status'] === 'Approved Internally' ? ' open' : '' ?>><summary class="btn btn-primary w-100"><i class="ri-quill-pen-line me-1"></i>Send for signature</summary>
              <form method="post" action="<?= e(url('documents', ['a' => 'send'])) ?>" class="vstack gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <label class="form-label small mb-0" for="rcp">Recipient (verified <?= $d['client_id'] ? 'authorised signatory' : 'vendor login' ?>)</label>
                <select class="form-select form-select-sm" id="rcp" name="recipient" required><?php foreach ($recipients as $rc): ?><option value="<?= $rc['id'] ?>"><?= e($rc['username'] . ' · ' . $rc['email'] . ($rc['designation'] ? ' · ' . $rc['designation'] : '')) ?></option><?php endforeach; ?></select>
                <div class="d-flex gap-2 align-items-center small"><label for="days">Expires in</label><input class="form-control form-control-sm" style="width:80px" type="number" id="days" name="days" min="1" max="90" value="<?= (int) setting('doc_sign_days', 14) ?>"> days</div>
                <label class="small"><input type="checkbox" name="allow_download" value="1" checked> Recipient may download before signing</label>
                <label class="small"><input type="checkbox" name="allow_changes" value="1" checked> Recipient may decline or request changes</label>
                <label class="small"><input type="checkbox" name="countersign" value="1" <?= val("SELECT 1 FROM hrm_client_signatures WHERE document_id = ? AND party = 'company'", [$d['id']]) ? '' : 'checked' ?>> Company countersignature required after the recipient signs</label>
                <button class="btn btn-primary btn-sm">Send secure signing request</button>
                <div class="small text-muted">The exact text (SHA-256) is locked for signing. Editing it later creates a new version and invalidates this request.</div>
              </form></details>
          <?php elseif ($act('send')): ?><div class="small text-muted"><?= $d['client_id'] ? 'This client has no authorised signatory login with an e-mail yet (Users &amp; Roles).' : 'This vendor has no active login yet.' ?></div><?php endif; ?>
          <?php if ($act('sign_internal') && $d['content'] !== null && !val("SELECT 1 FROM hrm_client_signatures WHERE document_id = ? AND party = 'company'", [$d['id']])): ?>
            <details class="doc-panel"><summary class="btn btn-light border w-100"><i class="ri-edit-2-line me-1"></i>Attach company signature</summary>
              <form method="post" action="<?= e(url('documents', ['a' => 'sign_internal'])) ?>" class="vstack gap-2 mt-2" data-sign-form><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <div class="sig-pad" data-sig><div class="sig-head"><span class="small fw-semibold">Signature</span><div class="btn-group btn-group-sm"><button type="button" class="btn btn-light border active" data-sig-mode="draw">Draw</button><button type="button" class="btn btn-light border" data-sig-mode="type">Type</button></div></div>
                  <canvas aria-label="Signature box"></canvas><input type="text" class="form-control sig-type d-none" placeholder="Type your name" maxlength="60"><div class="sig-foot"><span class="small text-muted" data-sig-hint>Sign inside the box</span><button type="button" class="btn btn-sm btn-link p-0" data-sig-clear>Clear</button></div>
                  <input type="hidden" name="signature" data-sig-out></div>
                <input class="form-control form-control-sm" name="signed_name" required placeholder="Authorised signatory's full name" value="<?= e(setting('onboarding_signatory', '')) ?>">
                <input class="form-control form-control-sm" name="signer_designation" required placeholder="Designation">
                <label class="small"><input type="checkbox" name="sign_consent" value="1"> I am authorised to sign this version for the company</label>
                <button class="btn btn-sm btn-primary" data-sig-submit>Attach signature to version <?= (int) $d['version'] ?></button>
                <div class="small text-muted">Recorded as an internal drawn signature (not a certificate-based digital signature).</div></form></details>
          <?php endif; ?>
          <?php if ($act('version')): ?>
            <details class="doc-panel"><summary class="btn btn-light border w-100"><i class="ri-upload-2-line me-1"></i>Upload revised Word document</summary>
              <form method="post" enctype="multipart/form-data" action="<?= e(url('documents', ['a' => 'version'])) ?>" class="vstack gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <input class="form-control form-control-sm" type="file" name="file" accept=".docx,.doc" required><button class="btn btn-sm btn-primary">Create version <?= (int) $d['version'] + 1 ?></button></form></details>
          <?php endif; ?>
          <?php if ($act('cancel') && in_array($d['status'], DOC_OPEN_SIGN, true)): ?>
            <details class="doc-panel"><summary class="btn btn-light border w-100"><i class="ri-shield-check-line me-1"></i>Executed outside the portal</summary>
              <form method="post" enctype="multipart/form-data" action="<?= e(url('documents', ['a' => 'external'])) ?>" class="vstack gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <select class="form-select form-select-sm" name="method"><option value="dsc">Certificate-based digital signature (DSC)</option><option value="aadhaar_esign">Aadhaar eSign (provider)</option><option value="wet_ink">Wet-ink signature</option><option value="notarised">Notarised / e-stamped</option></select>
                <input class="form-control form-control-sm" name="signer_name" required placeholder="Signer's name"><input class="form-control form-control-sm" name="signer_designation" placeholder="Designation">
                <input class="form-control form-control-sm" name="reference" required placeholder="Provider / stamp / notary reference"><input class="form-control form-control-sm" type="file" name="executed" accept=".pdf" required>
                <button class="btn btn-sm btn-primary">Upload executed PDF</button></form></details>
          <?php endif; ?>
          <?php if ($act('cancel')): ?><?= $post('cancel', '<i class="ri-close-circle-line me-1"></i>Cancel document', 'btn btn-outline-danger w-100', '', 'Why is this document being cancelled? (recorded and sent to the recipient if it was shared)') ?><?php endif; ?>
          <?php if (!array_filter(['review', 'approve', 'send', 'sign_internal', 'version', 'cancel'], $act)): ?><div class="small text-muted">No actions available for this version (<?= e(doc_status_label($d)) ?>).</div><?php endif; ?>
        </div></div>
        <?php if ($act('meta')): ?>
        <form class="card" method="post" action="<?= e(url('documents', ['a' => 'meta'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
          <div class="card-header-lite">Details</div><div class="card-body vstack gap-2">
            <input class="form-control form-control-sm" name="title" maxlength="190" value="<?= e($d['title']) ?>" aria-label="Title">
            <select class="form-select form-select-sm" name="doc_type" aria-label="Document type"><?php foreach (DOC_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($d['doc_type'] ?: (DOC_LEGACY_TYPES[$d['kind']] ?? '')) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <select class="form-select form-select-sm" name="service_category" aria-label="Service category"><?php foreach (DOC_CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= doc_category($d) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <?php if ($d['vendor_id']): ?><label class="small"><input type="checkbox" name="vendor_editable" value="1" <?= $d['vendor_editable'] ? 'checked' : '' ?>> Vendor may edit this document</label><?php endif; ?>
            <button class="btn btn-sm btn-light border">Save details</button></div></form>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite">Versions</div>
          <?php foreach (array_reverse($chain) as $v): ?><a class="list-row text-body<?= (int) $v['id'] === (int) $d['id'] ? ' active' : '' ?>" href="<?= e(url('documents', ['a' => 'view', 'id' => $v['id']])) ?>"><div class="grow"><div class="title">Version <?= (int) $v['version'] ?></div><div class="sub"><?= fdate($v['created_at'], 'd M Y') ?><?= $v['sent_at'] ? ' · sent ' . fdate($v['sent_at'], 'd M') : '' ?><?= $v['signed_at'] ? ' · signed ' . fdate($v['signed_at'], 'd M') : '' ?></div></div><?= doc_status_pill($v) ?></a><?php endforeach; ?></div>
        <div class="card"><div class="card-header-lite">Signatures</div>
          <?php foreach ($sigs as $s): ?><div class="list-row"><div class="grow"><div class="title"><?= e($s['signer_name']) ?> <span class="small text-muted"><?= e($s['party']) ?></span></div>
            <div class="sub"><?= e(DOC_METHOD_LABELS[$s['method']] ?? $s['method']) ?> · <?= fdate($s['signed_at'], 'd M Y, h:i A') ?><br>SHA-256 <?= e(substr($s['content_hash'], 0, 16)) ?>…<?= $s['content_hash'] !== $d['content_hash'] ? ' <span class="text-danger">(different text)</span>' : '' ?></div></div></div><?php endforeach; ?>
          <?php if (!$sigs): ?><div class="card-body small text-muted">Not signed yet.</div><?php endif; ?>
          <div class="card-body small text-muted border-top">Recorded methods are shown as they are: OTP e-mail verification with a drawn mark is an electronic signature, not a certificate-based digital signature. Whether a document needs DSC, Aadhaar eSign, stamp duty or witnesses is a legal decision; use "Executed outside the portal" for those.</div></div>
        <div class="card"><div class="card-header-lite">Access</div>
          <?php foreach ($access as $ac): ?><div class="list-row"><div class="grow"><div class="title small"><?= e($ac['username'] ?? '—') ?> · <?= e($ac['permission']) ?></div><div class="sub"><?= fdate($ac['granted_at'], 'd M Y') ?><?= $ac['revoked_at'] ? ' · revoked ' . fdate($ac['revoked_at'], 'd M Y') : '' ?></div></div>
            <?php if (!$ac['revoked_at'] && $act('revoke') && $ac['permission'] === 'view'): ?><?= $post('revoke', 'Revoke', 'btn btn-sm btn-link text-danger p-0', 'Revoke this recipient\'s access?', '', ['access_id' => $ac['id']]) ?><?php endif; ?></div><?php endforeach; ?>
          <?php if (!$access): ?><div class="card-body small text-muted">Only internal users can see this document until it is sent.</div><?php endif; ?></div>
      </div>
    </div>
    <?php
    page_end(['js/onboarding.js', 'js/doceditor.js']);
    return;
}

/* ───────────── List ───────────── */
$f = ['type' => input('type'), 'category' => input('category'), 'status' => input('status'), 'client_id' => int_input('client_id'), 'vendor_id' => int_input('vendor_id'), 'from' => valid_date(input('from')), 'to' => valid_date(input('to')), 'q' => trim((string) input('q'))];
$w = ["$scopeW", "d.kind <> 'offer_letter'", "d.status <> 'Void'"];
$p = $scopeP;
if (input('all') !== '1') $w[] = 'd.is_current = 1';
if (isset(DOC_TYPES[$f['type']])) { $w[] = '(d.doc_type = ? OR (d.doc_type IS NULL AND d.kind = ?))'; array_push($p, $f['type'], array_search($f['type'], DOC_LEGACY_TYPES, true) ?: '-'); }
if (isset(DOC_CATEGORIES[$f['category']])) { $w[] = '(d.service_category = ? OR (d.service_category IS NULL AND e.service = ?))'; array_push($p, $f['category'], ['recruitment' => 'hr', 'it_dev' => 'it'][$f['category']] ?? '-'); }
if ($f['status'] === 'Sent for Signature') { $w[] = "d.status = 'Awaiting Signature' AND d.viewed_at IS NULL"; }
elseif ($f['status'] === 'Viewed') { $w[] = "d.status = 'Awaiting Signature' AND d.viewed_at IS NOT NULL"; }
elseif ($f['status']) { $w[] = 'd.status = ?'; $p[] = $f['status']; }
if ($f['client_id']) { $w[] = 'd.client_id = ?'; $p[] = $f['client_id']; }
if ($f['vendor_id']) { $w[] = 'd.vendor_id = ?'; $p[] = $f['vendor_id']; }
if ($f['from']) { $w[] = 'd.created_at >= ?'; $p[] = $f['from']; }
if ($f['to']) { $w[] = 'd.created_at < ?'; $p[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')); }
if ($f['q'] !== '') { $w[] = '(d.title LIKE ? OR c.name LIKE ? OR v.name LIKE ? OR e.ref LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
$where = implode(' AND ', $w);
$from = 'FROM hrm_client_documents d LEFT JOIN hrm_clients c ON c.id = d.client_id LEFT JOIN hrm_vendors v ON v.id = d.vendor_id LEFT JOIN hrm_engagements e ON e.id = d.engagement_id';
$per = list_per_page('documents', 20);
$page = max(1, int_input('page', 1));
$total = (int) val("SELECT COUNT(*) $from WHERE $where", $p);
$rows = all("SELECT d.*, c.name client_name, v.name vendor_name, e.ref, e.service eng_service $from WHERE $where ORDER BY d.updated_at DESC, d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$k = one("SELECT COALESCE(SUM(d.status IN ('Draft','In Review','Approved Internally','Change Requested')), 0) prep, COALESCE(SUM(d.status IN ('Awaiting Signature','Partially Signed')), 0) out_sign,
                 COALESCE(SUM(d.status = 'Signed'), 0) signed, COALESCE(SUM(d.status IN ('Declined','Expired')), 0) attention $from WHERE $scopeW AND d.kind <> 'offer_letter' AND d.is_current = 1", $scopeP);
[$clientsOpt, $vendorsOpt] = doc_party_options();
$params = array_filter(['r' => 'documents'] + $f + ['all' => input('all')], fn($v) => $v !== null && $v !== '' && $v !== 0);
page_start('Agreements & Documents', ['Agreements & Documents'], '<a class="btn btn-primary" href="' . url('documents', ['a' => 'new']) . '"><i class="ri-file-add-line me-1"></i>New document</a>');
?>
<div class="kpi-strip mb-3">
  <?= kpi('In preparation', (int) $k['prep'], 'ri-draft-line', '#6366f1', url('documents', ['status' => 'Draft'])) ?>
  <?= kpi('Out for signature', (int) $k['out_sign'], 'ri-quill-pen-line', '#0ea5e9', url('documents', ['status' => 'Awaiting Signature'])) ?>
  <?= kpi('Signed', (int) $k['signed'], 'ri-checkbox-circle-line', '#16a34a', url('documents', ['status' => 'Signed'])) ?>
  <?= kpi('Declined / expired', (int) $k['attention'], 'ri-error-warning-line', '#dc2626', url('documents', ['status' => 'Declined'])) ?>
</div>
<div class="card list-card">
  <form class="toolbar flex-wrap" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="documents">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Title, client, vendor, reference"></div>
    <select class="form-select" name="type" onchange="this.form.submit()" aria-label="Document type"><option value="">All document types</option><?php foreach (DOC_TYPES as $kk => $l): ?><option value="<?= $kk ?>" <?= $f['type'] === $kk ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="category" onchange="this.form.submit()" aria-label="Service category"><option value="">All services</option><?php foreach (DOC_CATEGORIES as $kk => $l): ?><option value="<?= $kk ?>" <?= $f['category'] === $kk ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Any status</option><?php foreach (['Draft', 'In Review', 'Approved Internally', 'Sent for Signature', 'Viewed', 'Partially Signed', 'Signed', 'Declined', 'Change Requested', 'Expired', 'Superseded', 'Cancelled'] as $st): ?><option <?= $f['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select>
    <select class="form-select" name="client_id" onchange="this.form.submit()" aria-label="Client"><option value="">All clients</option><?php foreach ($clientsOpt as $kk => $l): ?><option value="<?= $kk ?>" <?= $f['client_id'] === (int) $kk ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <?php if ($vendorsOpt): ?><select class="form-select" name="vendor_id" onchange="this.form.submit()" aria-label="Vendor"><option value="">All vendors</option><?php foreach ($vendorsOpt as $kk => $l): ?><option value="<?= $kk ?>" <?= $f['vendor_id'] === (int) $kk ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
    <input class="form-control" type="date" name="from" value="<?= e((string) $f['from']) ?>" aria-label="From" style="max-width:150px"><input class="form-control" type="date" name="to" value="<?= e((string) $f['to']) ?>" aria-label="To" style="max-width:150px">
    <label class="small text-nowrap"><input type="checkbox" name="all" value="1" <?= input('all') === '1' ? 'checked' : '' ?> onchange="this.form.submit()"> All versions</label>
    <button class="btn btn-light border">Filter</button><span class="ms-auto small text-muted"><?= indian_number($total) ?> document(s)</span></form>
  <?php if (!$rows): empty_state('ri-file-shield-2-line', 'No documents', 'Upload a Word agreement or start from a template.', '<a class="btn btn-primary" href="' . url('documents', ['a' => 'new']) . '">New document</a>'); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="documents"><thead><tr><th>Document</th><th>Type</th><th>Service</th><th>Client / vendor</th><th>Version</th><th>Status</th><th>Updated</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $u = url('documents', ['a' => 'view', 'id' => $r['id']]); ?>
    <tr class="row-link" data-href="<?= e($u) ?>"><td><a class="fw-semibold" href="<?= e($u) ?>"><?= e($r['title']) ?></a><div class="small text-muted"><?= e($r['ref'] ?: ($r['engagement_id'] ? '#' . $r['engagement_id'] : 'Company-level')) ?><?= $r['file_format'] ? ' · ' . strtoupper(e($r['file_format'])) : '' ?></div></td>
      <td class="small"><?= e(doc_type_label($r)) ?></td><td class="small"><?= e(DOC_CATEGORIES[doc_category($r) ?? ''] ?? '—') ?></td>
      <td class="small"><?= $r['client_id'] ? e($r['client_name']) : '<i class="ri-team-fill"></i> ' . e($r['vendor_name']) ?></td><td>v<?= (int) $r['version'] ?></td><td><?= doc_status_pill($r) ?></td><td class="small text-nowrap"><?= time_ago($r['updated_at'] ?: $r['created_at']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?= pagination($total, $page, $per, $params, true) ?>
  <?php endif; ?>
</div>
<?php
page_end();
