<?php
/**
 * HR Document Centre (manage_hr_documents; approve_hr_documents approves; manage_induction runs induction). See app/lib/hrdocs.php.
 *   index (templates)  category → type dropdowns, saved templates      tnew / tcreate · template (id): preview, versions, edit, replace, approve, (de)activate, delete
 *   new / generate     recipient copy from a template (candidate or employee)     docs · doc (id): edit, approve, share, revoke, new version, history
 *   types              categories and document types                               signatures: signatory library (signature images)
 *   induction · ind_edit · report (+ export, remind)                               search (JSON, recipient picker)
 * Every action re-checks permissions on the server; files are streamed only after those checks.
 */

$canManage = hrd_can_manage();
$canApprove = hrd_can_approve();
$canInd = hrd_can_induction();
if (!$canManage && !$canApprove && !$canInd) authorize('__never__');
$a = $_GET['a'] ?? ($canManage || $canApprove ? 'index' : 'induction');
$tplActions = ['index', 'tnew', 'tcreate', 'template', 'tmeta', 'tversion', 'tedit', 'tstatus', 'tapprove', 'tdelete', 'tfile', 'tdocx', 'new', 'generate', 'search', 'docs', 'doc', 'dsave', 'dsubmit', 'dapprove', 'dreject', 'dshare', 'drevoke', 'dversion', 'dfile', 'types', 'type_save', 'cat_save', 'signatures', 'signatories', 'sig_save', 'sig_toggle', 'sigimg'];
if (in_array($a, $tplActions, true) && !$canManage && !$canApprove) authorize('__never__');
if (in_array($a, ['types', 'type_save', 'cat_save', 'signatures', 'signatories', 'sigimg', 'sig_save', 'sig_toggle', 'tnew', 'tcreate', 'tmeta', 'tversion', 'tedit', 'tstatus', 'tdelete', 'new', 'generate', 'dsave', 'dsubmit', 'dshare', 'drevoke', 'dversion'], true) && !$canManage) authorize('__never__');
$back = fn(string $u, string $type, string $msg) => [flash($type, $msg), redirect($u)];

function hrd_tabs(string $on): string
{
    $t = [];
    if (hrd_can_manage() || hrd_can_approve()) $t += ['index' => ['Templates', 'ri-file-copy-2-line'], 'docs' => ['Documents', 'ri-file-user-line']];
    if (hrd_can_manage()) $t += ['signatures' => ['Signatures', 'ri-quill-pen-line'], 'types' => ['Document types', 'ri-list-settings-line']];
    if (hrd_can_induction()) $t += ['induction' => ['Induction & policies', 'ri-book-open-line'], 'report' => ['Completion report', 'ri-bar-chart-box-line']];
    $h = '<nav class="list-tabs mb-3" aria-label="HR Document Centre">';
    foreach ($t as $k => [$l, $i]) $h .= '<a class="' . ($on === $k ? 'active' : '') . '" href="' . e(url('hrdocs', ['a' => $k])) . '"><i class="' . $i . ' me-1"></i>' . e($l) . '</a>';
    return $h . '</nav>';
}

/** Category → type selects (types filtered in the browser; the server re-validates the type). */
function hrd_type_selects(?int $typeId, bool $required = true, string $prefix = 'f'): string
{
    $types = hrd_types();
    $cat = $typeId ? (int) (array_values(array_filter($types, fn($t) => (int) $t['id'] === $typeId))[0]['category_id'] ?? 0) : 0;
    $h = '<div class="col-md-6"><label class="form-label" for="' . $prefix . '_cat">Document category' . ($required ? ' <span class="text-danger">*</span>' : '') . '</label><select class="form-select" id="' . $prefix . '_cat" data-cat-select' . ($required ? ' required' : '') . '><option value="">' . ($required ? 'Choose…' : 'All categories') . '</option>';
    foreach (hrd_categories() as $c) $h .= '<option value="' . (int) $c['id'] . '"' . ($cat === (int) $c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    $h .= '</select></div><div class="col-md-6"><label class="form-label" for="' . $prefix . '_type">Document type' . ($required ? ' <span class="text-danger">*</span>' : '') . '</label><select class="form-select" id="' . $prefix . '_type" name="type_id" data-type-select' . ($required ? ' required' : '') . '><option value="">' . ($required ? 'Choose a category first' : 'All types') . '</option>';
    foreach ($types as $t) $h .= '<option value="' . (int) $t['id'] . '" data-cat="' . (int) $t['category_id'] . '"' . ($typeId === (int) $t['id'] ? ' selected' : '') . '>' . e($t['name']) . '</option>';
    return $h . '</select></div>';
}

/* ═════════════════════════ Recipient search (JSON): minimal identifiers only ═════════════════════════ */
if ($a === 'search') {
    $q = trim((string) input('q'));
    $out = [];
    if (mb_strlen($q) >= 2) {
        $like = "%$q%";
        $cid = preg_match('/^(?:cand-?)?(\d+)$/i', $q, $m) ? (int) $m[1] : 0;
        foreach (all("SELECT id, name, email FROM hrm_candidates WHERE record_status <> 'Deleted' AND (name LIKE ? OR email LIKE ? OR id = ?) ORDER BY id DESC LIMIT 8", [$like, $like, $cid]) as $c) {
            $out[] = ['value' => 'c' . $c['id'], 'label' => $c['name'], 'meta' => 'Candidate #' . $c['id'] . ($c['email'] ? ' · ' . mask_email($c['email']) : '')];
        }
        foreach (all("SELECT id, emp_code, first_name, last_name, email, official_email FROM hrm_employees WHERE first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', COALESCE(last_name, '')) LIKE ? OR emp_code LIKE ? OR email LIKE ? OR official_email LIKE ? ORDER BY id DESC LIMIT 8", [$like, $like, $like, $like, $like, $like]) as $e) {
            $out[] = ['value' => 'e' . $e['id'], 'label' => trim($e['first_name'] . ' ' . $e['last_name']), 'meta' => 'Employee ' . $e['emp_code'] . ($e['official_email'] ? ' · ' . mask_email($e['official_email']) : ($e['email'] ? ' · ' . mask_email($e['email']) : ''))];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

/* ═════════════════════════ Templates ═════════════════════════ */
if ($a === 'tcreate' && is_post()) {
    $type = one('SELECT * FROM hrm_hr_doc_types WHERE id = ? AND active = 1', [int_input('type_id')]);
    $name = mb_substr(trim((string) input('name')), 0, 190);
    try {
        if (!$type) throw new RuntimeException('Choose the document category and type.');
        if (mb_strlen($name) < 3) throw new RuntimeException('Give the template a name.');
        $file = input('mode') === 'blank' ? null : hrd_store_file('file');
        db()->beginTransaction();
        $id = insert('hrm_hr_templates', ['type_id' => $type['id'], 'name' => $name, 'subcategory' => mb_substr(trim((string) input('subcategory')), 0, 120) ?: null,
            'description' => mb_substr(trim((string) input('description')), 0, 1000) ?: null, 'applies_to' => isset(HR_APPLIES_TO[input('applies_to')]) ? input('applies_to') : 'All',
            'current_version' => 0, 'created_by' => user()['id']]);
        hrd_add_version($id, $file, $file ? null : '<h2>' . e($name) . '</h2><p>Dear {{candidate_name}},</p><p></p><p>Regards,<br>{{signatory_name}}<br>{{signatory_designation}}</p>', $file ? 'Uploaded ' . $file[3] : 'Blank template');
        db()->commit();
        hrd_event(['template_id' => $id], 'tpl_created', $type['name'] . ($file ? ' · ' . $file[3] . ' (' . strtoupper($file[1]) . ')' : ' · blank'));
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['_old'] = $_POST;
        $back(url('hrdocs', ['a' => 'tnew']), 'danger', e($ex->getMessage()));
    }
    $back(url('hrdocs', ['a' => 'template', 'id' => $id]), 'success', 'Template saved in the Document Centre (version 1). It stays available for every future document.');
}

if ($a === 'tnew') {
    page_start('Upload template', ['HR Document Centre' => url('hrdocs'), 'Upload template']); ?>
    <form class="card form-block" method="post" enctype="multipart/form-data" action="<?= e(url('hrdocs', ['a' => 'tcreate'])) ?>" style="max-width:900px"><?= csrf_field() ?>
      <div class="form-block-head"><span class="fb-icon"><i class="ri-upload-cloud-2-line"></i></span><div><h6>Template</h6><small>Saved permanently in secure storage; replace it later with a new version.</small></div></div>
      <div class="form-block-body"><div class="row g-3">
        <?= hrd_type_selects(int_input('type_id') ?: (int) old('type_id', 0) ?: null) ?>
        <?= form_field('name', ['label' => 'Document name', 'required' => true, 'col' => 'col-md-8', 'maxlength' => 190, 'placeholder' => 'e.g. Offer Letter — Freshers 2026'], old('name')) ?>
        <?= form_field('applies_to', ['label' => 'Applies to', 'type' => 'select', 'options' => HR_APPLIES_TO, 'col' => 'col-md-4'], old('applies_to', 'All')) ?>
        <?= form_field('subcategory', ['label' => 'Subcategory', 'col' => 'col-md-4', 'maxlength' => 120, 'placeholder' => 'e.g. Technology team'], old('subcategory')) ?>
        <?= form_field('description', ['label' => 'Description', 'col' => 'col-md-8', 'maxlength' => 1000], old('description')) ?>
        <div class="col-12"><label class="form-label">Start from</label><div class="doc-modes" style="grid-template-columns:repeat(2,minmax(0,1fr))">
          <label><input type="radio" name="mode" value="upload" checked> <span><i class="ri-file-word-2-line"></i><strong>Upload a file</strong><small>.docx (editable), .doc (text preview; convert to edit) or .pdf (reference / read-only)</small></span></label>
          <label><input type="radio" name="mode" value="blank"> <span><i class="ri-file-line"></i><strong>Write it here</strong><small>Starts with a short letter you edit in the browser</small></span></label></div></div>
        <div class="col-12" data-mode-for="upload"><label class="form-label" for="file">File</label><input class="form-control" type="file" id="file" name="file" accept=".docx,.doc,.pdf">
          <div class="form-text">Max <?= (int) setting('hr_doc_max_mb', 10) ?> MB. Word files are checked (genuine Word package, no macros). Use placeholders like <code>{{candidate_name}}</code>, <code>{{designation}}</code>, <code>{{joining_date}}</code> in the text.</div></div>
      </div></div>
      <div class="form-actions px-3 pb-3"><a class="btn btn-light border ms-auto" href="<?= e(url('hrdocs')) ?>">Cancel</a><button class="btn btn-primary"><i class="ri-check-line me-1"></i>Save template</button></div>
    </form>
    <?php
    page_end(['js/hrdocs.js']);
    return;
}

if (in_array($a, ['tmeta', 'tversion', 'tedit', 'tstatus', 'tapprove', 'tdelete'], true) && is_post()) {
    $t = hrd_template(int_input('id')) ?? not_found();
    $u = url('hrdocs', ['a' => 'template', 'id' => $t['id']]);
    try {
        switch ($a) {
            case 'tmeta':
                $type = one('SELECT * FROM hrm_hr_doc_types WHERE id = ? AND active = 1', [int_input('type_id')]);
                if (!$type) throw new RuntimeException('Choose a valid document type.');
                $name = mb_substr(trim((string) input('name')), 0, 190);
                if (mb_strlen($name) < 3) throw new RuntimeException('Give the template a name.');
                update('hrm_hr_templates', ['type_id' => $type['id'], 'name' => $name, 'subcategory' => mb_substr(trim((string) input('subcategory')), 0, 120) ?: null,
                    'description' => mb_substr(trim((string) input('description')), 0, 1000) ?: null, 'applies_to' => isset(HR_APPLIES_TO[input('applies_to')]) ? input('applies_to') : 'All',
                    'updated_by' => user()['id'], 'updated_at' => date('Y-m-d H:i:s')], (int) $t['id']);
                hrd_event(['template_id' => $t['id']], 'tpl_meta', "$name · {$type['name']}");
                $back($u, 'success', 'Details saved.');
            case 'tversion':
                $file = hrd_store_file('file');
                $v = hrd_add_version((int) $t['id'], $file, null, mb_substr(trim((string) input('note')), 0, 255) ?: 'Replaced with ' . $file[3]);
                hrd_event(['template_id' => $t['id']], 'tpl_version', "Version $v · {$file[3]}");
                $back($u, 'success', "Version $v is now the active template. Documents generated earlier keep their own copy.");
            case 'tedit':
                if ($t['content'] === null) throw new RuntimeException('This template is a PDF or unreadable file; upload a .docx to edit it.');
                $html = doc_clean_html((string) ($_POST['content'] ?? ''));
                if (trim(strip_tags($html)) === '') throw new RuntimeException('The template is empty.');
                if ($html === doc_clean_html((string) $t['content'])) throw new RuntimeException('Nothing changed.');
                $v = hrd_add_version((int) $t['id'], null, $html, mb_substr(trim((string) input('note')), 0, 255) ?: 'Edited in the browser');
                hrd_event(['template_id' => $t['id']], 'tpl_edited', "Version $v");
                $back($u, 'success', "Saved as version $v.");
            case 'tstatus':
                $st = $t['status'] === 'Active' ? 'Inactive' : 'Active';
                update('hrm_hr_templates', ['status' => $st, 'updated_by' => user()['id'], 'updated_at' => date('Y-m-d H:i:s')], (int) $t['id']);
                hrd_event(['template_id' => $t['id']], 'tpl_status', $st);
                $back($u, 'success', "Template is now $st.");
            case 'tapprove':
                if (!$canApprove) authorize('__never__');
                if ($t['approval_status'] !== 'Pending') throw new RuntimeException('This version is not waiting for approval.');
                if ((int) $t['updated_by'] === (int) user()['id'] && !is_super_admin()) throw new RuntimeException('Someone other than the person who changed it has to approve it.');
                update('hrm_hr_templates', ['approval_status' => 'Approved', 'approved_by' => user()['id'], 'approved_at' => date('Y-m-d H:i:s')], (int) $t['id']);
                hrd_event(['template_id' => $t['id']], 'tpl_approved', 'Version ' . $t['current_version']);
                $back($u, 'success', 'Template version approved.');
            case 'tdelete':
                if (mb_strlen(trim((string) input('note'))) < 3) throw new RuntimeException('Give a reason for deleting the template.');
                update('hrm_hr_templates', ['deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => user()['id'], 'status' => 'Inactive'], (int) $t['id']);
                hrd_event(['template_id' => $t['id']], 'tpl_deleted', trim((string) input('note')));
                $back(url('hrdocs'), 'success', 'Template deleted. Documents already generated, shared or acknowledged from it are kept unchanged.');
        }
    } catch (RuntimeException $ex) {
        $back($u, 'danger', e($ex->getMessage()));
    }
}

if ($a === 'tfile' || $a === 'tdocx') {
    $t = hrd_template(int_input('id')) ?? not_found();
    $v = one('SELECT * FROM hrm_hr_template_versions WHERE template_id = ? AND version = ?', [$t['id'], int_input('v', (int) $t['current_version'])]) ?? not_found();
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $t['name']), '-') . '-v' . $v['version'];
    hrd_event(['template_id' => $t['id']], 'downloaded', ($a === 'tfile' ? 'Original file' : '.docx') . ' of version ' . $v['version']);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if ($a === 'tfile') {
        if (!$v['file'] || !is_file(STORAGE_DIR . '/uploads/' . $v['file'])) not_found();
        $ext = strtolower(pathinfo($v['file'], PATHINFO_EXTENSION));
        header('Content-Type: ' . (['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: ' . ($ext === 'pdf' && input('inline') ? 'inline' : 'attachment') . '; filename="' . $name . '.' . $ext . '"');
        readfile(STORAGE_DIR . '/uploads/' . $v['file']);
        exit;
    }
    if ($v['content'] === null) not_found();
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $name . '.docx"');
    echo html_to_docx((string) $v['content'], '');
    exit;
}

if ($a === 'template') {
    $t = hrd_template(int_input('id')) ?? not_found();
    $versions = all('SELECT v.*, u.username FROM hrm_hr_template_versions v LEFT JOIN it_login u ON u.id = v.uploaded_by WHERE v.template_id = ? ORDER BY v.version DESC', [$t['id']]);
    $events = all('SELECT ev.*, l.username FROM hrm_hr_doc_events ev LEFT JOIN it_login l ON l.id = ev.login_id WHERE ev.template_id = ? ORDER BY ev.id DESC LIMIT 50', [$t['id']]);
    $generated = (int) val('SELECT COUNT(*) FROM hrm_hr_documents WHERE template_id = ?', [$t['id']]);
    $hdr = '<a class="btn btn-light border" href="' . e(url('hrdocs', ['a' => 'new', 'template_id' => $t['id']])) . '"><i class="ri-file-user-line me-1"></i>Prepare for a person</a>';
    page_start($t['name'], ['HR Document Centre' => url('hrdocs'), $t['type_name']], $hdr); ?>
    <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
      <span class="eng-svc-icon sm"><i class="ri-file-copy-2-line"></i></span>
      <div class="grow"><div class="small text-muted"><?= e($t['category']) ?> · <?= e($t['type_name']) ?><?= $t['subcategory'] ? ' · ' . e($t['subcategory']) : '' ?> · <?= e(HR_APPLIES_TO[$t['applies_to']] ?? $t['applies_to']) ?></div>
        <h5 class="fw-bold m-0"><?= e($t['name']) ?> <?= badge($t['status']) ?> <?= $t['approval_status'] === 'Pending' ? '<span class="pill pill-warning">Approval pending</span>' : ($t['approval_status'] === 'Approved' ? '<span class="pill pill-success">Approved</span>' : '') ?></h5>
        <div class="small text-muted">Version <?= (int) $t['current_version'] ?> · <?= e(strtoupper((string) ($t['file_format'] ?: 'html'))) ?> · uploaded by <?= e($t['creator'] ?? '—') ?> <?= fdate($t['created_at']) ?><?= $t['updated_at'] ? ' · updated ' . time_ago($t['updated_at']) . ' by ' . e($t['modifier'] ?? '—') : '' ?> · used for <?= $generated ?> document(s)</div></div>
    </div></div>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php if ($t['content'] !== null && $canManage): ?>
        <form class="card" method="post" action="<?= e(url('hrdocs', ['a' => 'tedit'])) ?>" data-doc-editor><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <div class="doc-toolbar" role="toolbar" aria-label="Formatting">
            <?php foreach ([['bold', 'ri-bold', 'Bold'], ['italic', 'ri-italic', 'Italic'], ['underline', 'ri-underline', 'Underline'], ['h2', 'ri-h-2', 'Heading'], ['h3', 'ri-h-3', 'Sub-heading'], ['p', 'ri-text', 'Paragraph'],
                           ['insertUnorderedList', 'ri-list-unordered', 'Bullets'], ['insertOrderedList', 'ri-list-ordered', 'Numbering'], ['table', 'ri-table-line', 'Insert table'], ['undo', 'ri-arrow-go-back-line', 'Undo']] as [$cmd, $ic, $tt]): ?>
              <button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $tt ?>" aria-label="<?= $tt ?>"><i class="<?= $ic ?>"></i></button>
            <?php endforeach; ?>
            <select class="form-select form-select-sm" style="max-width:220px" data-insert-ph aria-label="Insert placeholder"><option value="">Insert placeholder…</option><?php foreach (HR_PLACEHOLDERS as $k => $l): ?><option value="{{<?= $k ?>}}"><?= e($l) ?></option><?php endforeach; ?></select>
            <span class="ms-auto small text-muted" data-doc-state>Saved</span></div>
          <div class="doc-page"><div class="doc-edit policy-body" contenteditable="true" data-doc-body><?= $t['content'] ?></div></div>
          <textarea name="content" hidden></textarea>
          <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center"><input class="form-control form-control-sm" name="note" maxlength="255" placeholder="What changed? (version note)" style="max-width:360px">
            <button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save as version <?= (int) $t['current_version'] + 1 ?></button></div>
        </form>
        <?php elseif ($t['file_format'] === 'pdf'): ?>
          <div class="card"><div class="card-header-lite">Preview (PDF — reference / read-only)</div><iframe title="Template preview" src="<?= e(url('hrdocs', ['a' => 'tfile', 'id' => $t['id'], 'inline' => 1])) ?>" style="width:100%;height:640px;border:0"></iframe></div>
        <?php else: ?>
          <div class="card payslip m-0"><div class="card-body p-4"><?= $t['content'] !== null ? '<div class="policy-body">' . $t['content'] . '</div>' : '<p class="text-muted">No preview available. Download the file.</p>' ?></div></div>
        <?php endif; ?>
        <?php if ($t['file_format'] === 'doc'): ?><div class="alert alert-info small mb-0"><i class="ri-information-line me-1"></i>Legacy .doc: the text was extracted for preview. Formatting may be lost; saving an edit stores the text as a new version (the original .doc is kept in version history). For full fidelity upload a .docx.</div><?php endif; ?>
        <div class="card"><div class="card-header-lite">Version history</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Version</th><th>Source</th><th>Note</th><th>By</th><th>Date</th><th></th></tr></thead><tbody>
          <?php foreach ($versions as $v): ?><tr><td>v<?= (int) $v['version'] ?><?= (int) $v['version'] === (int) $t['current_version'] ? ' <span class="pill pill-info">Active</span>' : '' ?></td><td class="small"><?= $v['file'] ? e($v['original_name'] ?: basename($v['file'])) . ' (' . e(strtoupper((string) $v['file_format'])) . ')' : 'Edited text' ?></td>
            <td class="small"><?= e($v['note'] ?? '') ?></td><td class="small"><?= e($v['username'] ?? '—') ?></td><td class="small text-nowrap"><?= fdate($v['created_at'], 'd M Y, h:i A') ?></td>
            <td class="text-end text-nowrap"><?php if ($v['file']): ?><a class="btn btn-sm btn-icon" href="<?= e(url('hrdocs', ['a' => 'tfile', 'id' => $t['id'], 'v' => $v['version']])) ?>" title="Download original" aria-label="Download original"><i class="ri-download-2-line"></i></a><?php endif; ?>
              <?php if ($v['content'] !== null): ?><a class="btn btn-sm btn-icon" href="<?= e(url('hrdocs', ['a' => 'tdocx', 'id' => $t['id'], 'v' => $v['version']])) ?>" title="Download as .docx" aria-label="Download as docx"><i class="ri-file-word-2-line"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <div class="card"><div class="card-header-lite">Audit trail</div><div class="card-body"><ol class="lock-timeline">
          <?php foreach ($events as $ev): ?><li><strong><?= e(HR_EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></strong><?= $ev['detail'] ? '<div class="small">' . e($ev['detail']) . '</div>' : '' ?><small class="text-muted"><?= fdate($ev['created_at'], 'd M Y, h:i A') ?> · <?= e($ev['username'] ?? 'system') ?></small></li><?php endforeach; ?>
        </ol></div></div>
      </div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">Actions</div><div class="card-body vstack gap-2">
          <?php if ($t['approval_status'] === 'Pending' && $canApprove): ?><?= post_button(url('hrdocs', ['a' => 'tapprove']), '<i class="ri-check-double-line me-1"></i>Approve version ' . (int) $t['current_version'], 'btn btn-success w-100', 'Approve this template version for use?', ['id' => $t['id']]) ?><?php endif; ?>
          <?php if ($canManage): ?>
          <details class="doc-panel"><summary class="btn btn-light border w-100"><i class="ri-upload-2-line me-1"></i>Replace / upload new version</summary>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('hrdocs', ['a' => 'tversion'])) ?>" class="vstack gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <input class="form-control form-control-sm" type="file" name="file" accept=".docx,.doc,.pdf" required><input class="form-control form-control-sm" name="note" maxlength="255" placeholder="What changed?">
              <button class="btn btn-sm btn-primary">Upload as version <?= (int) $t['current_version'] + 1 ?></button><div class="small text-muted">Earlier versions and every document already generated stay unchanged.</div></form></details>
          <?= post_button(url('hrdocs', ['a' => 'tstatus']), $t['status'] === 'Active' ? '<i class="ri-pause-circle-line me-1"></i>Deactivate' : '<i class="ri-play-circle-line me-1"></i>Activate', 'btn btn-light border w-100', $t['status'] === 'Active' ? 'Deactivate this template? It can no longer be used for new documents.' : '', ['id' => $t['id']]) ?>
          <button type="button" class="btn btn-outline-danger w-100" data-post="<?= e(url('hrdocs', ['a' => 'tdelete'])) ?>" data-prompt="Why are you deleting this template? Documents already generated from it are kept." data-fields='<?= e(json_encode(['id' => $t['id']])) ?>'><i class="ri-delete-bin-6-line me-1"></i>Delete template</button>
          <?php endif; ?>
          <?php if ($t['content'] !== null): ?><a class="btn btn-light border w-100" href="<?= e(url('hrdocs', ['a' => 'tdocx', 'id' => $t['id']])) ?>"><i class="ri-file-word-2-line me-1"></i>Download as .docx</a><?php endif; ?>
          <?php if ($t['file']): ?><a class="btn btn-light border w-100" href="<?= e(url('hrdocs', ['a' => 'tfile', 'id' => $t['id']])) ?>"><i class="ri-download-2-line me-1"></i>Download original</a><?php endif; ?>
        </div></div>
        <?php if ($canManage): ?>
        <form class="card" method="post" action="<?= e(url('hrdocs', ['a' => 'tmeta'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <div class="card-header-lite">Details</div><div class="card-body"><div class="row g-2">
            <?= hrd_type_selects((int) $t['type_id'], true, 'm') ?>
            <div class="col-12"><input class="form-control form-control-sm" name="name" maxlength="190" value="<?= e($t['name']) ?>" aria-label="Name" required></div>
            <div class="col-6"><input class="form-control form-control-sm" name="subcategory" maxlength="120" value="<?= e($t['subcategory'] ?? '') ?>" placeholder="Subcategory" aria-label="Subcategory"></div>
            <div class="col-6"><select class="form-select form-select-sm" name="applies_to" aria-label="Applies to"><?php foreach (HR_APPLIES_TO as $k => $l): ?><option value="<?= $k ?>" <?= $t['applies_to'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><textarea class="form-control form-control-sm" name="description" rows="2" maxlength="1000" placeholder="Description" aria-label="Description"><?= e($t['description'] ?? '') ?></textarea></div>
            <div class="col-12"><button class="btn btn-sm btn-light border w-100">Save details</button></div></div></div></form>
        <?php endif; ?>
        <div class="card card-body small text-muted"><strong class="text-body">Placeholders</strong> are filled from verified records (candidate profile, onboarding case, employee record). Missing values are highlighted in the generated copy so HR completes them before sharing.</div>
      </div>
    </div>
    <?php
    page_end(['js/doceditor.js', 'js/hrdocs.js']);
    return;
}

/* ═════════════════════════ Generate a recipient copy ═════════════════════════ */
if ($a === 'generate' && is_post()) {
    $t = hrd_template(int_input('template_id'));
    $rec = (string) input('recipient');
    $cid = $rec !== '' && $rec[0] === 'c' ? (int) substr($rec, 1) : 0;
    $eid = $rec !== '' && $rec[0] === 'e' ? (int) substr($rec, 1) : 0;
    try {
        if (!$t) throw new RuntimeException('Choose a saved template.');
        if ($cid && !val('SELECT id FROM hrm_candidates WHERE id = ?', [$cid])) $cid = 0;
        if ($eid && !val('SELECT id FROM hrm_employees WHERE id = ?', [$eid])) $eid = 0;
        if (!$cid && !$eid) throw new RuntimeException('Choose the candidate or employee from the search results.');
        if ($eid && !$cid) $cid = (int) val('SELECT candidate_id FROM hrm_employees WHERE id = ?', [$eid]) ?: 0;
        $id = hrd_generate($t, $cid ?: null, $eid ?: null, int_input('signatory_id') ?: null, mb_substr(trim((string) input('title')), 0, 190));
    } catch (RuntimeException $ex) {
        $back(url('hrdocs', ['a' => 'new', 'template_id' => int_input('template_id')]), 'danger', e($ex->getMessage()));
    }
    $miss = hrd_missing_in((string) val('SELECT content FROM hrm_hr_documents WHERE id = ?', [$id]));
    $back(url('hrdocs', ['a' => 'doc', 'id' => $id]), $miss ? 'warning' : 'success', 'Draft created from the template.' . ($miss ? ' Missing details are highlighted: ' . e(implode(', ', $miss)) . '.' : ''));
}

if ($a === 'new') {
    $tid = int_input('template_id');
    $pre = $tid ? hrd_template($tid) : null;
    $preRec = int_input('employee_id') ? 'e' . int_input('employee_id') : (int_input('candidate_id') ? 'c' . int_input('candidate_id') : '');
    $preLabel = $preRec === '' ? '' : ($preRec[0] === 'e' ? (string) val("SELECT CONCAT(first_name, ' ', COALESCE(last_name, ''), ' · ', emp_code) FROM hrm_employees WHERE id = ?", [(int) substr($preRec, 1)]) : (string) val("SELECT CONCAT(name, ' · Candidate #', id) FROM hrm_candidates WHERE id = ?", [(int) substr($preRec, 1)]));
    $tpls = all("SELECT t.id, t.name, t.type_id, ty.category_id FROM hrm_hr_templates t JOIN hrm_hr_doc_types ty ON ty.id = t.type_id WHERE t.status = 'Active' AND t.deleted_at IS NULL AND t.approval_status <> 'Pending' ORDER BY t.name");
    page_start('Prepare a document', ['HR Document Centre' => url('hrdocs'), 'Prepare a document']); ?>
    <form class="card form-block" method="post" action="<?= e(url('hrdocs', ['a' => 'generate'])) ?>" style="max-width:900px" data-generate-form><?= csrf_field() ?>
      <div class="form-block-head"><span class="fb-icon"><i class="ri-file-user-line"></i></span><div><h6>Document and recipient</h6><small>A draft copy is created for this person from the saved template; the template itself never changes.</small></div></div>
      <div class="form-block-body"><div class="row g-3">
        <?= hrd_type_selects($pre ? (int) $pre['type_id'] : null, false, 'g') ?>
        <div class="col-12"><label class="form-label" for="g_tpl">Saved template <span class="text-danger">*</span></label><select class="form-select" id="g_tpl" name="template_id" required data-tpl-select><option value="">Choose…</option>
          <?php foreach ($tpls as $tp): ?><option value="<?= (int) $tp['id'] ?>" data-type="<?= (int) $tp['type_id'] ?>" data-cat="<?= (int) $tp['category_id'] ?>" <?= $tid === (int) $tp['id'] ? 'selected' : '' ?>><?= e($tp['name']) ?></option><?php endforeach; ?></select>
          <?php if (!$tpls): ?><div class="form-text text-danger">No active templates yet. <a href="<?= e(url('hrdocs', ['a' => 'tnew'])) ?>">Upload one</a>.</div><?php endif; ?></div>
        <div class="col-md-8 position-relative"><label class="form-label" for="g_rec_q">Candidate or employee <span class="text-danger">*</span></label>
          <input class="form-control" id="g_rec_q" type="search" autocomplete="off" placeholder="Search by name, candidate ID, employee ID or e-mail" value="<?= e($preLabel) ?>" data-recipient-search="<?= e(url('hrdocs', ['a' => 'search'])) ?>" aria-describedby="g_rec_h">
          <input type="hidden" name="recipient" value="<?= e($preRec) ?>" required data-recipient-value><div class="dropdown-menu w-100" data-recipient-menu></div>
          <div class="form-text" id="g_rec_h">Results show only the name, ID and a masked e-mail.</div></div>
        <div class="col-md-4"><label class="form-label" for="g_sig">Authorised signatory</label><select class="form-select" id="g_sig" name="signatory_id"><option value="">— None —</option><?php foreach (sig_options() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?= form_field('title', ['label' => 'Title (optional)', 'col' => 'col-12', 'maxlength' => 190, 'placeholder' => 'Blank = document type + name'], '') ?>
      </div></div>
      <div class="form-actions px-3 pb-3"><a class="btn btn-light border ms-auto" href="<?= e(url('hrdocs')) ?>">Cancel</a><button class="btn btn-primary"><i class="ri-magic-line me-1"></i>Generate draft</button></div>
    </form>
    <?php
    page_end(['js/hrdocs.js']);
    return;
}

/* ═════════════════════════ Generated documents ═════════════════════════ */
if (in_array($a, ['dsave', 'dsubmit', 'dapprove', 'dreject', 'dshare', 'drevoke', 'dversion'], true) && is_post()) {
    $d = hrd_doc(int_input('id')) ?? not_found();
    $u = url('hrdocs', ['a' => 'doc', 'id' => $d['id']]);
    try {
        switch ($a) {
            case 'dsave':
                hrd_doc_require($d, 'edit');
                hrd_save_content($d, (string) ($_POST['content'] ?? ''), mb_substr(trim((string) input('note')), 0, 200));
                $miss = hrd_missing_in(doc_clean_html((string) ($_POST['content'] ?? '')));
                $back($u, $miss ? 'warning' : 'success', 'Saved.' . ($miss ? ' Still missing: ' . e(implode(', ', $miss)) . '.' : ''));
            case 'dsubmit':
                hrd_doc_require($d, 'submit');
                if ($miss = hrd_missing_in($d['content'])) throw new RuntimeException('Fill in the missing details first: ' . implode(', ', $miss) . '.');
                update('hrm_hr_documents', ['status' => 'Pending Approval', 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
                hrd_event(['document_id' => $d['id']], 'submitted', (string) input('note'));
                $back($u, 'success', 'Sent for approval.');
            case 'dapprove':
                hrd_doc_require($d, 'approve');
                update('hrm_hr_documents', ['status' => 'Approved', 'approved_by' => user()['id'], 'approved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
                hrd_event(['document_id' => $d['id']], 'approved', (string) input('note'));
                $back($u, 'success', 'Approved. HR can now share it.');
            case 'dreject':
                hrd_doc_require($d, 'approve');
                if (mb_strlen(trim((string) input('note'))) < 3) throw new RuntimeException('Say what should change.');
                update('hrm_hr_documents', ['status' => 'Draft', 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
                hrd_event(['document_id' => $d['id']], 'rejected', trim((string) input('note')));
                $back($u, 'success', 'Returned to draft with your comments.');
            case 'dshare':
                hrd_doc_require($d, 'share');
                hrd_share($d, int_input('days'), !empty($_POST['allow_download']));
                $back($u, 'success', 'Shared. The recipient sees it in their portal and has been e-mailed a sign-in link.');
            case 'drevoke':
                hrd_doc_require($d, 'revoke');
                if (mb_strlen(trim((string) input('note'))) < 3) throw new RuntimeException('Give a reason.');
                update('hrm_hr_documents', ['status' => 'Revoked', 'revoked_by' => user()['id'], 'revoked_at' => date('Y-m-d H:i:s')], (int) $d['id']);
                hrd_event(['document_id' => $d['id'], 'employee_id' => $d['employee_id']], 'revoked', trim((string) input('note')));
                $back($u, 'success', 'Access revoked. The recipient can no longer open it.');
            case 'dversion':
                hrd_doc_require($d, 'version');
                $nid = hrd_new_version($d);
                $back(url('hrdocs', ['a' => 'doc', 'id' => $nid]), 'success', 'New draft version created. The shared copy stays on record as it was.');
        }
    } catch (RuntimeException $ex) {
        $back($u, 'danger', e($ex->getMessage()));
    }
}

if ($a === 'dfile') {
    $d = hrd_doc(int_input('id')) ?? not_found();
    hrd_doc_require($d, 'download');
    hrd_event(['document_id' => $d['id']], 'downloaded', 'HR');
    header('Cache-Control: private, no-store');
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $d['title']), '-') . '-v' . $d['version'];
    if ($d['content'] === null) {
        if (!$d['file'] || !is_file(STORAGE_DIR . '/uploads/' . $d['file'])) not_found();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
        readfile(STORAGE_DIR . '/uploads/' . $d['file']);
        exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $name . '.docx"');
    echo html_to_docx(doc_clean_html((string) $d['content']), '');
    exit;
}

if ($a === 'doc') {
    $d = hrd_doc(int_input('id')) ?? not_found();
    hrd_doc_require($d, 'view');
    $missing = hrd_missing_in($d['content']);
    $events = all('SELECT ev.*, l.username FROM hrm_hr_doc_events ev LEFT JOIN it_login l ON l.id = ev.login_id WHERE ev.document_id = ? ORDER BY ev.id DESC LIMIT 60', [$d['id']]);
    $chain = [];
    for ($x = $d; $x && count($chain) < 30; $x = $x['supersedes_id'] ? one('SELECT * FROM hrm_hr_documents WHERE id = ?', [$x['supersedes_id']]) : null) $chain[] = $x;
    $login = hrd_recipient_login($d['candidate_id'] ? (int) $d['candidate_id'] : null, $d['employee_id'] ? (int) $d['employee_id'] : null);
    $p = fn(string $act, string $label, string $cls, string $confirm = '', string $prompt = '') => str_replace('<button', '<button' . ($prompt ? ' data-prompt="' . e($prompt) . '"' : ''), post_button(url('hrdocs', ['a' => $act]), $label, $cls, $confirm, ['id' => $d['id']]));
    page_start($d['title'], ['HR Document Centre' => url('hrdocs'), 'Documents' => url('hrdocs', ['a' => 'docs']), 'Version ' . $d['version']],
        '<a class="btn btn-light border" href="' . e(url('hrdocs', ['a' => 'dfile', 'id' => $d['id']])) . '"><i class="ri-download-2-line me-1"></i>Download</a><button class="btn btn-light border" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>'); ?>
    <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
      <div class="grow"><div class="small text-muted"><?= e($d['type_name'] ?? 'Document') ?> · from <?= e($d['template_name'] ?? '—') ?> v<?= (int) $d['template_version'] ?> · version <?= (int) $d['version'] ?></div>
        <h5 class="fw-bold m-0"><?= e($d['title']) ?> <?= badge($d['status']) ?></h5>
        <div class="small">For <?= $d['employee_id'] ? '<a href="' . e(url('employees', ['a' => 'view', 'id' => $d['employee_id']])) . '">' . e(trim($d['emp_name'])) . '</a> (' . e($d['emp_code']) . ')' : '' ?><?= $d['candidate_id'] && !$d['employee_id'] ? '<a href="' . e(url('candidates', ['a' => 'view', 'id' => $d['candidate_id']])) . '">' . e($d['cand_name']) . '</a> (candidate #' . (int) $d['candidate_id'] . ')' : '' ?>
          · response: <?= e(HR_RESPONSES[$d['response']] ?? $d['response']) ?><?= $d['shared_at'] ? ' · shared ' . fdate($d['shared_at'], 'd M Y, h:i A') . ' with ' . e(mask_email((string) $d['recipient_email'])) : '' ?><?= $d['expires_at'] ? ' · respond by ' . fdate($d['expires_at'], 'd M Y') : '' ?></div></div>
      <?php if ($d['superseded_by']): ?><a class="btn btn-sm btn-warning" href="<?= e(url('hrdocs', ['a' => 'doc', 'id' => $d['superseded_by']])) ?>">Open the newer version</a><?php endif; ?>
    </div></div>
    <?php if ($missing): ?><div class="alert alert-danger"><i class="ri-error-warning-line me-1"></i><strong>Missing details:</strong> <?= e(implode(', ', $missing)) ?>. Replace each highlighted marker (or remove the sentence) before it can be <?= hrd_approval_required() ? 'sent for approval' : 'shared' ?>.</div><?php endif; ?>
    <?php if ($d['response_note']): ?><div class="alert alert-info small"><strong>Recipient's note:</strong> <?= e($d['response_note']) ?></div><?php endif; ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php if (hrd_doc_can($d, 'edit') && $d['content'] !== null): ?>
          <form class="card" method="post" action="<?= e(url('hrdocs', ['a' => 'dsave'])) ?>" data-doc-editor><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <div class="doc-toolbar" role="toolbar" aria-label="Formatting">
              <?php foreach ([['bold', 'ri-bold', 'Bold'], ['italic', 'ri-italic', 'Italic'], ['underline', 'ri-underline', 'Underline'], ['h2', 'ri-h-2', 'Heading'], ['p', 'ri-text', 'Paragraph'], ['insertUnorderedList', 'ri-list-unordered', 'Bullets'], ['insertOrderedList', 'ri-list-ordered', 'Numbering'], ['undo', 'ri-arrow-go-back-line', 'Undo']] as [$cmd, $ic, $tt]): ?>
                <button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $tt ?>" aria-label="<?= $tt ?>"><i class="<?= $ic ?>"></i></button>
              <?php endforeach; ?><span class="ms-auto small text-muted" data-doc-state>Saved</span></div>
            <div class="doc-page"><div class="doc-edit policy-body" contenteditable="true" data-doc-body><?= $d['content'] ?></div></div>
            <textarea name="content" hidden></textarea>
            <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center"><input class="form-control form-control-sm" name="note" maxlength="200" placeholder="What changed? (audit note)" style="max-width:360px"><button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save draft</button></div>
            <div class="card-body pt-0 small text-muted">Signature images placed by the template are kept when you save; the image is not editable here.</div>
          </form>
        <?php elseif ($d['content'] !== null): ?>
          <div class="card payslip m-0"><div class="card-body p-4 p-md-5"><div class="policy-body"><?= $d['content'] ?></div></div></div>
        <?php else: ?>
          <div class="card"><div class="card-header-lite">PDF document (read-only)</div><div class="card-body small text-muted">This document was prepared from a PDF template and is shared as that file. Download it to view.</div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite">Status &amp; audit history</div><div class="card-body"><ol class="lock-timeline">
          <?php foreach ($events as $ev): ?><li><strong><?= e(HR_EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></strong><?= $ev['detail'] ? '<div class="small">' . e($ev['detail']) . '</div>' : '' ?><small class="text-muted"><?= fdate($ev['created_at'], 'd M Y, h:i A') ?> · <?= e($ev['username'] ?? 'system') ?></small></li><?php endforeach; ?>
        </ol></div></div>
      </div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">Actions</div><div class="card-body vstack gap-2">
          <?php if (hrd_doc_can($d, 'submit')): ?><?= $p('dsubmit', '<i class="ri-send-plane-line me-1"></i>Send for approval', 'btn btn-primary w-100') ?><?php endif; ?>
          <?php if (hrd_doc_can($d, 'approve')): ?><?= $p('dapprove', '<i class="ri-check-double-line me-1"></i>Approve', 'btn btn-success w-100', 'Approve this document for sharing?') ?><?= $p('dreject', 'Return to draft', 'btn btn-outline-warning w-100', '', 'What should be changed?') ?><?php elseif ($d['status'] === 'Pending Approval'): ?><div class="small text-muted">Waiting for an approver<?= (int) $d['created_by'] === (int) user()['id'] && !is_super_admin() ? ' (someone other than you)' : '' ?>.</div><?php endif; ?>
          <?php if (hrd_doc_can($d, 'share')): ?>
            <form method="post" action="<?= e(url('hrdocs', ['a' => 'dshare'])) ?>" class="vstack gap-2 border rounded p-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <div class="small fw-semibold">Share through the HRMS</div>
              <div class="small"><?= $login ? 'Recipient portal login: <strong>' . e(mask_email((string) $login['email'])) . '</strong>' : '<span class="text-danger">No active portal login for this person yet.</span>' ?></div>
              <div class="d-flex gap-2 align-items-center small"><label for="days">Respond within</label><input class="form-control form-control-sm" style="width:80px" type="number" id="days" name="days" min="0" max="180" value="14"> days <span class="text-muted">(0 = no deadline)</span></div>
              <label class="small"><input type="checkbox" name="allow_download" value="1" checked> Recipient may download</label>
              <button class="btn btn-primary btn-sm" <?= $login && !$missing ? '' : 'disabled' ?> data-confirm="Share this document with the recipient? It becomes visible in their portal and they get an e-mail with a sign-in link."><i class="ri-share-forward-line me-1"></i>Share through HRMS</button></form>
          <?php endif; ?>
          <?php if (hrd_doc_can($d, 'version')): ?><?= $p('dversion', '<i class="ri-git-branch-line me-1"></i>Create new version', 'btn btn-light border w-100', 'Create a new draft version? The shared copy stays on record and loses recipient access.') ?><?php endif; ?>
          <?php if (hrd_doc_can($d, 'revoke')): ?><?= $p('drevoke', '<i class="ri-forbid-line me-1"></i>Revoke access', 'btn btn-outline-danger w-100', '', 'Why are you revoking access?') ?><?php endif; ?>
        </div></div>
        <div class="card"><div class="card-header-lite">Versions</div><?php foreach ($chain as $v): ?><a class="list-row text-body<?= (int) $v['id'] === (int) $d['id'] ? ' active' : '' ?>" href="<?= e(url('hrdocs', ['a' => 'doc', 'id' => $v['id']])) ?>"><div class="grow"><div class="title">Version <?= (int) $v['version'] ?></div><div class="sub"><?= fdate($v['created_at'], 'd M Y') ?></div></div><?= badge($v['status']) ?></a><?php endforeach; ?></div>
        <div class="card card-body small text-muted"><strong class="text-body">What the statuses mean.</strong> Viewed = opened by the signed-in recipient. Acknowledged / Accepted / Declined = their explicit response (time and IP recorded). A signature image placed by HR is not the recipient's signature.</div>
      </div>
    </div>
    <?php
    page_end(['js/doceditor.js']);
    return;
}

if ($a === 'docs') {
    $f = ['status' => (string) input('status'), 'type_id' => int_input('type_id'), 'q' => trim((string) input('q'))];
    $w = ['d.superseded_by IS NULL']; $pp = [];
    if (in_array($f['status'], HR_DOC_STATUSES, true)) { $w[] = $f['status'] === 'Shared' ? "d.status IN ('Shared','Viewed')" : 'd.status = ?'; if ($f['status'] !== 'Shared') $pp[] = $f['status']; }
    if ($f['type_id']) { $w[] = 'd.type_id = ?'; $pp[] = $f['type_id']; }
    if ($f['q'] !== '') { $w[] = '(d.title LIKE ? OR c.name LIKE ? OR e.first_name LIKE ? OR e.emp_code LIKE ?)'; array_push($pp, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
    $from = 'FROM hrm_hr_documents d LEFT JOIN hrm_candidates c ON c.id = d.candidate_id LEFT JOIN hrm_employees e ON e.id = d.employee_id LEFT JOIN hrm_hr_doc_types ty ON ty.id = d.type_id WHERE ' . implode(' AND ', $w);
    $per = list_per_page('hrdocs', 20); $page = max(1, int_input('page', 1));
    $total = (int) val("SELECT COUNT(*) $from", $pp);
    $rows = all("SELECT d.*, c.name cand_name, CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) emp_name, e.emp_code, ty.name type_name $from ORDER BY d.updated_at DESC, d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $pp);
    page_start('HR Document Centre', ['HR Document Centre' => url('hrdocs'), 'Documents'], $canManage ? '<a class="btn btn-primary" href="' . e(url('hrdocs', ['a' => 'new'])) . '"><i class="ri-file-user-line me-1"></i>Prepare a document</a>' : '');
    echo hrd_tabs('docs'); ?>
    <div class="card list-card">
      <form class="toolbar flex-wrap" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="hrdocs"><input type="hidden" name="a" value="docs">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Title, name, employee ID"></div>
        <select class="form-select" name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Any status</option><?php foreach (HR_DOC_STATUSES as $s): ?><option <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
        <select class="form-select" name="type_id" onchange="this.form.submit()" aria-label="Type"><option value="">All types</option><?php foreach (hrd_types() as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $f['type_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['category'] . ' — ' . $t['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-light border">Filter</button><span class="ms-auto small text-muted"><?= $total ?> document(s)</span></form>
      <?php if (!$rows): empty_state('ri-file-user-line', 'No documents', 'Prepare one from a saved template.'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="hr-docs"><thead><tr><th>Document</th><th>Type</th><th>Recipient</th><th>Version</th><th>Created</th><th>Shared</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($rows as $r): $u = url('hrdocs', ['a' => 'doc', 'id' => $r['id']]); ?>
          <tr class="row-link" data-href="<?= e($u) ?>"><td><a class="fw-semibold" href="<?= e($u) ?>"><?= e($r['title']) ?></a></td><td class="small"><?= e($r['type_name'] ?? '—') ?></td>
            <td class="small"><?= $r['employee_id'] ? e(trim($r['emp_name'])) . ' <span class="text-muted">' . e($r['emp_code']) . '</span>' : e($r['cand_name']) . ' <span class="text-muted">candidate</span>' ?></td>
            <td>v<?= (int) $r['version'] ?></td><td class="small text-nowrap"><?= fdate($r['created_at']) ?></td><td class="small text-nowrap"><?= $r['shared_at'] ? fdate($r['shared_at']) : '—' ?></td><td><?= badge($r['status']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?= pagination($total, $page, $per, array_filter(['r' => 'hrdocs', 'a' => 'docs'] + $f), true) ?>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ═════════════════════════ Document types ═════════════════════════ */
if (($a === 'type_save' || $a === 'cat_save') && is_post()) {
    $id = int_input('id');
    $name = mb_substr(trim((string) input('name')), 0, $a === 'cat_save' ? 120 : 150);
    if (mb_strlen($name) < 2) $back(url('hrdocs', ['a' => 'types']), 'danger', 'Enter a name.');
    if ($a === 'cat_save') {
        $row = ['name' => $name, 'active' => !empty($_POST['active']) ? 1 : 0, 'sort' => int_input('sort')];
        $id ? update('hrm_hr_doc_categories', $row, $id) : insert('hrm_hr_doc_categories', $row);
    } else {
        $cat = int_input('category_id');
        if (!val('SELECT id FROM hrm_hr_doc_categories WHERE id = ?', [$cat])) $back(url('hrdocs', ['a' => 'types']), 'danger', 'Choose a category.');
        $row = ['category_id' => $cat, 'name' => $name, 'response' => isset(HR_RESPONSES[input('response')]) ? input('response') : 'acknowledge', 'active' => !empty($_POST['active']) ? 1 : 0, 'sort' => int_input('sort')];
        $id ? update('hrm_hr_doc_types', $row, $id) : insert('hrm_hr_doc_types', $row);
    }
    log_activity($id ? 'updated' : 'created', $a === 'cat_save' ? 'HR document category' : 'HR document type', $id, $name);
    $back(url('hrdocs', ['a' => 'types']), 'success', 'Saved.');
}

if ($a === 'types') {
    $cats = hrd_categories(false);
    $types = hrd_types(false);
    page_start('HR Document Centre', ['HR Document Centre' => url('hrdocs'), 'Document types']);
    echo hrd_tabs('types'); ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php foreach ($cats as $c): ?>
          <div class="card"><div class="card-header-lite"><span><?= e($c['name']) ?> <?= $c['active'] ? '' : '<span class="pill pill-neutral">Inactive</span>' ?></span></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Type</th><th>Recipient response</th><th>Order</th><th>Active</th><th></th></tr></thead><tbody>
            <?php foreach (array_filter($types, fn($t) => (int) $t['category_id'] === (int) $c['id']) as $t): ?>
              <tr><td colspan="5" class="p-0"><form class="d-flex flex-wrap gap-2 align-items-center p-2" method="post" action="<?= e(url('hrdocs', ['a' => 'type_save'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                <input class="form-control form-control-sm" name="name" value="<?= e($t['name']) ?>" style="flex:1 1 220px" aria-label="Type name" maxlength="150">
                <select class="form-select form-select-sm" name="response" style="max-width:190px" aria-label="Response"><?php foreach (HR_RESPONSES as $k => $l): ?><option value="<?= $k ?>" <?= $t['response'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <input class="form-control form-control-sm" type="number" name="sort" value="<?= (int) $t['sort'] ?>" style="width:70px" aria-label="Order">
                <label class="small"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?>> Active</label><button class="btn btn-sm btn-light border">Save</button></form></td></tr>
            <?php endforeach; ?></tbody></table></div></div>
        <?php endforeach; ?>
      </div>
      <div class="vstack gap-3">
        <form class="card card-body vstack gap-2" method="post" action="<?= e(url('hrdocs', ['a' => 'type_save'])) ?>"><?= csrf_field() ?><h6 class="fw-bold mb-0">Add a document type</h6>
          <select class="form-select form-select-sm" name="category_id" required aria-label="Category"><option value="">Category…</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
          <input class="form-control form-control-sm" name="name" required maxlength="150" placeholder="e.g. Background Verification Consent">
          <select class="form-select form-select-sm" name="response" aria-label="Response"><?php foreach (HR_RESPONSES as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'acknowledge' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
          <input type="hidden" name="active" value="1"><button class="btn btn-sm btn-primary">Add type</button></form>
        <form class="card card-body vstack gap-2" method="post" action="<?= e(url('hrdocs', ['a' => 'cat_save'])) ?>"><?= csrf_field() ?><h6 class="fw-bold mb-0">Categories</h6>
          <?php foreach ($cats as $c): ?><div class="small"><?= e($c['name']) ?> · <?= $c['active'] ? 'active' : 'inactive' ?></div><?php endforeach; ?>
          <input class="form-control form-control-sm" name="name" required maxlength="120" placeholder="New category"><input type="hidden" name="active" value="1"><input type="hidden" name="sort" value="<?= count($cats) + 1 ?>"><button class="btn btn-sm btn-light border">Add category</button></form>
        <div class="card card-body small text-muted">Deactivated types disappear from the dropdowns; templates and documents that use them are kept.</div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ═════════════════════════ Signatories ═════════════════════════ */
if ($a === 'sigimg') {
    $s = sig_find(int_input('id'), false) ?? not_found();
    if (!$s['image'] || !is_file(STORAGE_DIR . '/uploads/' . $s['image'])) not_found();
    header('Content-Type: ' . (str_ends_with($s['image'], '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, no-store');
    readfile(STORAGE_DIR . '/uploads/' . $s['image']);
    exit;
}

if (($a === 'sig_save' || $a === 'sig_toggle') && is_post()) {
    $s = int_input('id') ? (sig_find(int_input('id'), false) ?? not_found()) : null;
    if ($a === 'sig_toggle') {
        update('hrm_signatories', ['active' => $s['active'] ? 0 : 1, 'updated_by' => user()['id'], 'updated_at' => date('Y-m-d H:i:s')], (int) $s['id']);
        hrd_event(['signatory_id' => $s['id']], 'sig_changed', $s['active'] ? 'Deactivated' : 'Activated');
        $back(url('hrdocs', ['a' => 'signatories']), 'success', 'Saved.');
    }
    $name = mb_substr(trim((string) input('name')), 0, 150);
    $desig = mb_substr(trim((string) input('designation')), 0, 150);
    try {
        if (mb_strlen($name) < 3 || $desig === '') throw new RuntimeException('Enter the signatory\'s name and designation.');
        $img = store_upload('image', 'signatories', 1);
        if (!$s && !$img) throw new RuntimeException('Upload the signature image (PNG or JPG, max 1 MB).');
        if ($img) {
            [$w, $h] = @getimagesize(STORAGE_DIR . '/uploads/' . $img) ?: [0, 0];
            if ($w < 80 || $h < 20 || $w > 3000 || $h > 2000) { @unlink(STORAGE_DIR . '/uploads/' . $img); throw new RuntimeException('The signature image should be between 80×20 and 3000×2000 pixels.'); }
        }
        $row = ['name' => $name, 'designation' => $desig, 'email' => filter_var(input('email'), FILTER_VALIDATE_EMAIL) ? strtolower((string) input('email')) : null, 'notes' => mb_substr(trim((string) input('notes')), 0, 255) ?: null,
            'updated_by' => user()['id'], 'updated_at' => date('Y-m-d H:i:s')] + ($img ? ['image' => $img] : []);
        if ($s) { update('hrm_signatories', $row, (int) $s['id']); $sid = (int) $s['id']; }
        else { $sid = insert('hrm_signatories', $row + ['created_by' => user()['id']]); }
        hrd_event(['signatory_id' => $sid], $s ? 'sig_changed' : 'sig_added', "$name · $desig" . ($img ? ' · new signature image' : ''));
    } catch (RuntimeException $ex) {
        $back(url('hrdocs', ['a' => 'signatures']), 'danger', e($ex->getMessage()));
    }
    $back(url('hrdocs', ['a' => 'signatures']), 'success', 'Signatory saved. Documents prepared earlier keep the image they were issued with.');
}

if ($a === 'signatures' || $a === 'signatories') {
    $rows = all('SELECT s.*, (SELECT COUNT(*) FROM hrm_hr_doc_events ev WHERE ev.signatory_id = s.id AND ev.event = "signature_inserted") used FROM hrm_signatories s ORDER BY s.active DESC, s.name');
    page_start('HR Document Centre', ['HR Document Centre' => url('hrdocs'), 'Signatures']);
    echo hrd_tabs('signatures'); ?>
    <div class="alert alert-info small"><i class="ri-information-line me-1"></i>These are <strong>signature images</strong> HR places on prepared documents on behalf of an authorised signatory. They are not an employee's acknowledgement and not an electronic signature. Candidates e-sign offers and agreements separately (e-mail OTP + drawn signature, with audit trail).</div>
    <div class="grid grid-3">
      <div class="span-2 card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Signature</th><th>Signatory</th><th>Used</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $s): ?><tr><td><?php if ($s['image']): ?><img src="<?= e(url('hrdocs', ['a' => 'sigimg', 'id' => $s['id']])) ?>" alt="Signature of <?= e($s['name']) ?>" style="max-height:48px;max-width:180px;background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px"><?php endif; ?></td>
          <td><strong><?= e($s['name']) ?></strong><div class="small text-muted"><?= e($s['designation']) ?><?= $s['email'] ? ' · ' . e($s['email']) : '' ?></div></td><td class="small"><?= (int) $s['used'] ?> time(s)</td><td><?= $s['active'] ? badge('Active') : badge('Inactive') ?></td>
          <td class="text-end text-nowrap"><details class="d-inline-block text-start"><summary class="btn btn-sm btn-light border">Edit</summary>
            <form class="card card-body vstack gap-2 position-absolute end-0 shadow" style="z-index:5;min-width:280px" method="post" enctype="multipart/form-data" action="<?= e(url('hrdocs', ['a' => 'sig_save'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input class="form-control form-control-sm" name="name" value="<?= e($s['name']) ?>" required aria-label="Name"><input class="form-control form-control-sm" name="designation" value="<?= e($s['designation']) ?>" required aria-label="Designation">
              <input class="form-control form-control-sm" type="email" name="email" value="<?= e($s['email'] ?? '') ?>" placeholder="E-mail" aria-label="E-mail"><label class="small">Replace image <input class="form-control form-control-sm" type="file" name="image" accept=".png,.jpg,.jpeg"></label>
              <button class="btn btn-sm btn-primary">Save</button></form></details>
            <?= post_button(url('hrdocs', ['a' => 'sig_toggle']), $s['active'] ? 'Deactivate' : 'Activate', 'btn btn-sm btn-light border', $s['active'] ? 'Deactivate this signatory?' : '', ['id' => $s['id']]) ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-muted small p-4">No signatories yet.</td></tr><?php endif; ?>
      </tbody></table></div></div>
      <form class="card card-body vstack gap-2" method="post" enctype="multipart/form-data" action="<?= e(url('hrdocs', ['a' => 'sig_save'])) ?>"><?= csrf_field() ?><h6 class="fw-bold mb-0">Add signatory</h6>
        <input class="form-control" name="name" required maxlength="150" placeholder="Full name" aria-label="Full name"><input class="form-control" name="designation" required maxlength="150" placeholder="Designation, e.g. Director — HR" aria-label="Designation">
        <input class="form-control" type="email" name="email" placeholder="E-mail (optional)" aria-label="E-mail"><label class="small">Signature image (PNG with transparent background works best, max 1 MB)<input class="form-control" type="file" name="image" accept=".png,.jpg,.jpeg" required></label>
        <input class="form-control" name="notes" maxlength="255" placeholder="Authority / notes (optional)" aria-label="Notes">
        <button class="btn btn-primary">Save signatory</button></form>
    </div>
    <?php
    page_end();
    return;
}

/* ═════════════════════════ Induction & policies (HR / induction team) ═════════════════════════ */
if (in_array($a, ['induction', 'ind_edit', 'ind_save', 'ind_toggle', 'report', 'report_export', 'remind'], true) && !$canInd) authorize('__never__');

if ($a === 'ind_save' && is_post()) {
    $it = int_input('id') ? (ind_item(int_input('id')) ?? not_found()) : null;
    $kind = in_array(input('kind'), ['content', 'policy', 'template', 'file', 'link'], true) ? input('kind') : 'content';
    $title = mb_substr(trim((string) input('title')), 0, 190);
    $aud = in_array(input('audience'), ['all', 'department', 'employees', 'cohort'], true) ? input('audience') : 'all';
    try {
        if (mb_strlen($title) < 3) throw new RuntimeException('Give the item a title.');
        $row = ['title' => $title, 'kind' => $kind, 'mandatory' => !empty($_POST['mandatory']) ? 1 : 0, 'requires_ack' => !empty($_POST['requires_ack']) ? 1 : 0, 'audience' => $aud,
            'department_id' => $aud === 'department' ? (int_input('department_id') ?: null) : null, 'cohort_from' => $aud === 'cohort' ? valid_date(input('cohort_from')) : null, 'cohort_to' => $aud === 'cohort' ? valid_date(input('cohort_to')) : null,
            'due_days' => input('due_days') !== '' && input('due_days') !== null ? max(0, min(365, int_input('due_days'))) : null, 'effective_from' => valid_date(input('effective_from')), 'sort' => int_input('sort'),
            'policy_id' => null, 'template_id' => null, 'body' => null, 'url' => null, 'updated_by' => user()['id'], 'updated_at' => date('Y-m-d H:i:s')];
        if ($aud === 'department' && !$row['department_id']) throw new RuntimeException('Choose the department.');
        if ($kind === 'policy') { $row['policy_id'] = int_input('policy_id') ?: null; if (!$row['policy_id'] || !val('SELECT id FROM hrm_policies WHERE id = ?', [$row['policy_id']])) throw new RuntimeException('Choose a policy.'); }
        if ($kind === 'template') { $row['template_id'] = int_input('template_id') ?: null; if (!$row['template_id'] || !hrd_template($row['template_id'])) throw new RuntimeException('Choose a template from the Document Centre.'); }
        if ($kind === 'content') { $row['body'] = trim((string) input('body')); if (mb_strlen($row['body']) < 10) throw new RuntimeException('Write the induction content.'); }
        if ($kind === 'link') { $row['url'] = trim((string) input('url')); if (!preg_match('#^https://#i', $row['url']) || !filter_var($row['url'], FILTER_VALIDATE_URL)) throw new RuntimeException('Enter a full https:// link.'); }
        if ($kind === 'file') {
            $up = !empty($_FILES['file']['name']) ? store_upload('file', 'hrdocs', max(1, (int) setting('hr_doc_max_mb', 10))) : null;
            if ($up) $row['file'] = $up; elseif (!$it || !$it['file']) throw new RuntimeException('Upload the file (PDF or Word).');
        }
        // A material change asks everyone to acknowledge again (earlier acknowledgements stay on record for their version).
        if ($it && !empty($_POST['new_version'])) $row['version'] = (int) $it['version'] + 1;
        $ids = $aud === 'employees' ? array_values(array_filter(array_map('intval', (array) ($_POST['employees'] ?? [])))) : [];
        if ($aud === 'employees' && !$ids) throw new RuntimeException('Choose at least one employee.');
        if ($it) { update('hrm_induction_items', $row, (int) $it['id']); $iid = (int) $it['id']; } else { $iid = insert('hrm_induction_items', $row + ['created_by' => user()['id']]); }
        q('DELETE FROM hrm_induction_assignees WHERE item_id = ?', [$iid]);
        foreach ($ids as $e) if (val('SELECT id FROM hrm_employees WHERE id = ?', [$e])) insert('hrm_induction_assignees', ['item_id' => $iid, 'employee_id' => $e, 'assigned_by' => user()['id']]);
        hrd_event([], 'ind_item', "$title · " . ($it ? 'updated' . (isset($row['version']) ? ' (version ' . $row['version'] . ', re-acknowledgement required)' : '') : 'created') . " · audience $aud");
    } catch (RuntimeException $ex) {
        $_SESSION['_old'] = $_POST;
        $back(url('hrdocs', ['a' => 'ind_edit', 'id' => $it['id'] ?? null]), 'danger', e($ex->getMessage()));
    }
    $back(url('hrdocs', ['a' => 'induction']), 'success', 'Induction item saved.');
}

if ($a === 'ind_toggle' && is_post()) {
    $it = ind_item(int_input('id')) ?? not_found();
    update('hrm_induction_items', ['active' => $it['active'] ? 0 : 1, 'updated_by' => user()['id'], 'updated_at' => date('Y-m-d H:i:s')], (int) $it['id']);
    hrd_event([], 'ind_item', $it['title'] . ($it['active'] ? ' deactivated' : ' activated'));
    $back(url('hrdocs', ['a' => 'induction']), 'success', 'Saved. Acknowledgements already given are kept.');
}

if ($a === 'ind_edit') {
    $it = int_input('id') ? (ind_item(int_input('id')) ?? not_found()) : null;
    $o = fn($k, $d = '') => old($k, $it[$k] ?? $d);
    $assigned = $it ? array_map('intval', array_column(all('SELECT employee_id FROM hrm_induction_assignees WHERE item_id = ?', [$it['id']]), 'employee_id')) : [];
    $policies = array_column(all("SELECT id, CONCAT(title, ' (v', version, ', ', status, ')') l FROM hrm_policies WHERE status <> 'Archived' ORDER BY title"), 'l', 'id');
    $tpls = array_column(all("SELECT id, name FROM hrm_hr_templates WHERE deleted_at IS NULL AND status = 'Active' ORDER BY name"), 'name', 'id');
    page_start($it ? 'Edit induction item' : 'New induction item', ['HR Document Centre' => url('hrdocs', ['a' => 'induction']), 'Induction', $it ? $it['title'] : 'New']); ?>
    <form class="card form-block" method="post" enctype="multipart/form-data" action="<?= e(url('hrdocs', ['a' => 'ind_save'])) ?>" style="max-width:980px" data-ind-form><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($it['id'] ?? 0) ?>">
      <div class="form-block-head"><span class="fb-icon"><i class="ri-book-open-line"></i></span><div><h6>Induction item</h6><small>Configured here, not hard-coded: company introduction, handbook, policies, role-specific material.</small></div></div>
      <div class="form-block-body"><div class="row g-3">
        <?= form_field('title', ['label' => 'Title', 'required' => true, 'col' => 'col-md-8', 'maxlength' => 190], $o('title')) ?>
        <?= form_field('kind', ['label' => 'Content', 'type' => 'select', 'options' => ['content' => 'Text written here', 'policy' => 'HR policy (from HR Policies)', 'template' => 'Document Centre template', 'file' => 'Uploaded file (PDF / Word)', 'link' => 'External link (https)'], 'col' => 'col-md-4'], $o('kind', 'content')) ?>
        <div class="col-12" data-kind="content"><label class="form-label" for="f_body">Text</label><textarea class="form-control" id="f_body" name="body" rows="8"><?= e($o('body')) ?></textarea><div class="form-text">Formatting: <code>## Heading</code>, <code>- list</code>, <code>**bold**</code>.</div></div>
        <div class="col-12" data-kind="policy"><?= form_field('policy_id', ['label' => 'Policy', 'type' => 'select', 'options' => $policies, 'col' => 'col-12', 'help' => 'Employees acknowledge the policy\'s current published version; a new policy version asks again.'], $o('policy_id')) ?></div>
        <div class="col-12" data-kind="template"><?= form_field('template_id', ['label' => 'Template', 'type' => 'select', 'options' => $tpls, 'col' => 'col-12'], $o('template_id')) ?></div>
        <div class="col-12" data-kind="file"><label class="form-label" for="f_file">File</label><input class="form-control" type="file" id="f_file" name="file" accept=".pdf,.doc,.docx"><?= !empty($it['file']) ? '<div class="form-text">Current: ' . e(basename($it['file'])) . '</div>' : '' ?></div>
        <div class="col-12" data-kind="link"><?= form_field('url', ['label' => 'Link', 'type' => 'url', 'col' => 'col-12', 'placeholder' => 'https://…'], $o('url')) ?></div>
        <div class="col-md-4"><label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="mandatory" value="1" <?= $o('mandatory', 1) ? 'checked' : '' ?>> <span class="form-check-label">Mandatory (blocks completion)</span></label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="requires_ack" value="1" <?= $o('requires_ack', 1) ? 'checked' : '' ?>> <span class="form-check-label">Needs individual acknowledgement</span></label></div>
        <?= form_field('due_days', ['label' => 'Due (days after joining)', 'type' => 'number', 'min' => 0, 'max' => 365, 'col' => 'col-md-4'], $o('due_days')) ?>
        <?= form_field('effective_from', ['label' => 'Effective from', 'type' => 'date', 'col' => 'col-md-4'], $o('effective_from')) ?>
        <?= form_field('audience', ['label' => 'Assign to', 'type' => 'select', 'options' => ['all' => 'All employees', 'department' => 'A department', 'employees' => 'Selected employees', 'cohort' => 'Joining cohort (date range)'], 'col' => 'col-md-4'], $o('audience', 'all')) ?>
        <div class="col-md-8" data-aud="department"><?= form_field('department_id', ['label' => 'Department', 'type' => 'select', 'options' => relation_options(['table' => 'hrm_departments']), 'col' => 'col-12'], $o('department_id')) ?></div>
        <div class="col-md-8" data-aud="cohort"><div class="row g-2"><?= form_field('cohort_from', ['label' => 'Joined on / after', 'type' => 'date', 'col' => 'col-6'], $o('cohort_from')) ?><?= form_field('cohort_to', ['label' => 'Joined on / before', 'type' => 'date', 'col' => 'col-6'], $o('cohort_to')) ?></div></div>
        <div class="col-12" data-aud="employees"><label class="form-label" for="f_emps">Employees</label><select class="form-select" id="f_emps" name="employees[]" multiple size="8"><?php foreach (employee_options() as $id => $l): ?><option value="<?= $id ?>" <?= in_array((int) $id, $assigned, true) ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><div class="form-text">Ctrl / ⌘ + click to choose several.</div></div>
        <?= form_field('sort', ['label' => 'Order', 'type' => 'number', 'col' => 'col-md-2'], $o('sort', 0)) ?>
        <?php if ($it): ?><div class="col-md-10"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="new_version" value="1"> <span class="form-check-label">This is a material change — publish as version <?= (int) $it['version'] + 1 ?> and ask everyone to acknowledge again (earlier acknowledgements are kept)</span></label></div><?php endif; ?>
      </div></div>
      <div class="form-actions px-3 pb-3"><a class="btn btn-light border ms-auto" href="<?= e(url('hrdocs', ['a' => 'induction'])) ?>">Cancel</a><button class="btn btn-primary">Save</button></div>
    </form>
    <?php
    page_end(['js/hrdocs.js']);
    return;
}

if ($a === 'induction') {
    $items = all('SELECT i.*, p.title policy_title, p.version policy_version, d.name dept, (SELECT COUNT(*) FROM hrm_induction_assignees a WHERE a.item_id = i.id) assignees FROM hrm_induction_items i LEFT JOIN hrm_policies p ON p.id = i.policy_id LEFT JOIN hrm_departments d ON d.id = i.department_id ORDER BY i.active DESC, i.sort, i.id');
    page_start('HR Document Centre', ['HR Document Centre' => url('hrdocs'), 'Induction & policies'], '<a class="btn btn-primary" href="' . e(url('hrdocs', ['a' => 'ind_edit'])) . '"><i class="ri-add-line me-1"></i>New induction item</a>');
    echo hrd_tabs('induction'); ?>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Item</th><th>Content</th><th>Assigned to</th><th>Rules</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($items as $i): ?><tr><td class="fw-semibold"><?= e($i['title']) ?></td>
        <td class="small"><?= e(['content' => 'Text', 'policy' => 'Policy: ' . ($i['policy_title'] ?? '—'), 'template' => 'Template', 'file' => 'File', 'link' => 'Link'][$i['kind']] ?? $i['kind']) ?></td>
        <td class="small"><?= e(['all' => 'All employees', 'department' => 'Dept: ' . ($i['dept'] ?? '—'), 'employees' => (int) $i['assignees'] . ' employee(s)', 'cohort' => 'Joined ' . ($i['cohort_from'] ? fdate($i['cohort_from']) : '…') . ' – ' . ($i['cohort_to'] ? fdate($i['cohort_to']) : '…')][$i['audience']] ?? $i['audience']) ?></td>
        <td class="small"><?= $i['mandatory'] ? 'Mandatory' : 'Optional' ?> · <?= $i['requires_ack'] ? 'acknowledge' : 'mark as done' ?><?= $i['due_days'] !== null ? ' · due ' . (int) $i['due_days'] . ' d after joining' : '' ?><?= $i['effective_from'] ? ' · from ' . fdate($i['effective_from']) : '' ?></td>
        <td>v<?= e($i['kind'] === 'policy' ? (string) $i['policy_version'] : (string) $i['version']) ?></td><td><?= $i['active'] ? badge('Active') : badge('Inactive') ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-light border" href="<?= e(url('hrdocs', ['a' => 'ind_edit', 'id' => $i['id']])) ?>">Edit</a> <?= post_button(url('hrdocs', ['a' => 'ind_toggle']), $i['active'] ? 'Deactivate' : 'Activate', 'btn btn-sm btn-light border', $i['active'] ? 'Deactivate this item? Acknowledgements already given are kept.' : '', ['id' => $i['id']]) ?></td></tr><?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="7" class="text-muted small p-4">No induction items yet. Add the company introduction, handbook, code of conduct, leave &amp; attendance, information security, POSH and role-specific material.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php
    page_end();
    return;
}

if ($a === 'report' || $a === 'report_export' || ($a === 'remind' && is_post())) {
    $f = ['q' => trim((string) input('q')), 'department_id' => int_input('department_id'), 'status' => in_array(input('status'), ['pending', 'complete'], true) ? input('status') : '', 'joined_from' => valid_date(input('joined_from')), 'joined_to' => valid_date(input('joined_to'))];
    $rows = ind_report($f);
    if ($a === 'remind') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? [])) ?: array_column(array_filter($rows, fn($r) => $r['pending']), 'id');
        $n = ind_remind($ids);
        $back(url('hrdocs', ['a' => 'report'] + array_filter($f)), 'success', "$n reminder(s) sent (each employee at most once a day).");
    }
    if ($a === 'report_export') {
        hrd_event([], 'ind_item', 'Completion report exported (' . count($rows) . ' employees)');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="induction-completion-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Employee ID', 'Name', 'Department', 'Joined', 'Mandatory items', 'Completed', 'Percent', 'Overdue', 'Pending items']);
        foreach ($rows as $r) fputcsv($out, [$r['emp_code'], trim($r['first_name'] . ' ' . $r['last_name']), $r['dept'], $r['join_date'], $r['mandatory'], $r['done'], $r['percent'] . '%', $r['overdue'], implode('; ', $r['pending'])]);
        exit;
    }
    page_start('HR Document Centre', ['HR Document Centre' => url('hrdocs'), 'Completion report'],
        '<a class="btn btn-light border" href="' . e(url('hrdocs', ['a' => 'report_export'] + array_filter($f))) . '"><i class="ri-download-2-line me-1"></i>Export CSV</a>'
        . str_replace('<button', '<button data-confirm="E-mail a reminder to every employee in this list with pending mandatory items?"', post_button(url('hrdocs', ['a' => 'remind'] + array_filter($f)), '<i class="ri-notification-3-line me-1"></i>Remind all pending', 'btn btn-primary')));
    echo hrd_tabs('report');
    $pend = count(array_filter($rows, fn($r) => $r['pending'])); ?>
    <div class="kpi-strip mb-3"><?= kpi('Employees', count($rows), 'ri-team-line', '#6366f1') ?><?= kpi('Complete', count($rows) - $pend, 'ri-checkbox-circle-line', '#16a34a') ?><?= kpi('Pending', $pend, 'ri-time-line', '#f59e0b') ?><?= kpi('With overdue items', count(array_filter($rows, fn($r) => $r['overdue'])), 'ri-alarm-warning-line', '#dc2626') ?></div>
    <div class="card list-card">
      <form class="toolbar flex-wrap" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="hrdocs"><input type="hidden" name="a" value="report">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name or employee ID"></div>
        <select class="form-select" name="department_id" aria-label="Department"><option value="">All departments</option><?php foreach (relation_options(['table' => 'hrm_departments']) as $id => $l): ?><option value="<?= $id ?>" <?= $f['department_id'] === (int) $id ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="status" aria-label="Status"><option value="">Any status</option><option value="pending" <?= $f['status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="complete" <?= $f['status'] === 'complete' ? 'selected' : '' ?>>Complete</option></select>
        <input class="form-control" type="date" name="joined_from" value="<?= e((string) $f['joined_from']) ?>" aria-label="Joined from" style="max-width:150px"><input class="form-control" type="date" name="joined_to" value="<?= e((string) $f['joined_to']) ?>" aria-label="Joined to" style="max-width:150px">
        <button class="btn btn-light border">Filter</button></form>
      <?php if (!$rows): empty_state('ri-book-open-line', 'Nothing to report', 'Assign induction items to employees first.'); else: ?>
      <div class="table-responsive"><table class="table align-middle data-table" data-grid="ind-report"><thead><tr><th>Employee</th><th>Department</th><th>Joined</th><th>Progress</th><th>Pending mandatory items</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><a class="fw-semibold" href="<?= e(url('employees', ['a' => 'view', 'id' => $r['id']])) ?>"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></a><div class="small text-muted"><?= e($r['emp_code']) ?></div></td>
          <td class="small"><?= e($r['dept'] ?? '—') ?></td><td class="small text-nowrap"><?= fdate($r['join_date']) ?></td>
          <td style="min-width:140px"><div class="progress" style="height:6px"><div class="progress-bar <?= $r['percent'] === 100 ? 'bg-success' : '' ?>" style="width:<?= $r['percent'] ?>%"></div></div><small><?= $r['done'] ?> / <?= $r['mandatory'] ?></small></td>
          <td class="small"><?= $r['pending'] ? e(implode(', ', $r['pending'])) . ($r['overdue'] ? ' <span class="pill pill-danger">' . $r['overdue'] . ' overdue</span>' : '') : '<span class="pill pill-success">Complete</span>' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ═════════════════════════ Templates list (default) ═════════════════════════ */
$f = ['type_id' => int_input('type_id'), 'cat' => int_input('cat'), 'status' => in_array(input('status'), ['Active', 'Inactive'], true) ? input('status') : '', 'q' => trim((string) input('q'))];
$w = ['t.deleted_at IS NULL']; $pp = [];
if ($f['type_id']) { $w[] = 't.type_id = ?'; $pp[] = $f['type_id']; }
elseif ($f['cat']) { $w[] = 'ty.category_id = ?'; $pp[] = $f['cat']; }
if ($f['status']) { $w[] = 't.status = ?'; $pp[] = $f['status']; }
if ($f['q'] !== '') { $w[] = '(t.name LIKE ? OR t.subcategory LIKE ? OR t.description LIKE ?)'; array_push($pp, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
$rows = all('SELECT t.*, ty.name type_name, c.name category, v.file_format, u.username creator, (SELECT COUNT(*) FROM hrm_hr_documents d WHERE d.template_id = t.id) used
             FROM hrm_hr_templates t JOIN hrm_hr_doc_types ty ON ty.id = t.type_id JOIN hrm_hr_doc_categories c ON c.id = ty.category_id
             LEFT JOIN hrm_hr_template_versions v ON v.template_id = t.id AND v.version = t.current_version LEFT JOIN it_login u ON u.id = t.created_by
             WHERE ' . implode(' AND ', $w) . ' ORDER BY c.sort, ty.sort, t.name', $pp);
page_start('HR Document Centre', ['HR Document Centre'], $canManage ? '<a class="btn btn-light border" href="' . e(url('hrdocs', ['a' => 'new'])) . '"><i class="ri-file-user-line me-1"></i>Prepare a document</a><a class="btn btn-primary" href="' . e(url('hrdocs', ['a' => 'tnew'])) . '"><i class="ri-upload-cloud-2-line me-1"></i>Upload template</a>' : '');
echo hrd_tabs('index'); ?>
<div class="card list-card">
  <form class="toolbar flex-wrap" method="get" action="<?= base_path() ?>/dashboard/" data-tpl-filter><input type="hidden" name="r" value="hrdocs">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Template name, subcategory"></div>
    <select class="form-select" name="cat" data-cat-select aria-label="Document category"><option value="">All categories</option><?php foreach (hrd_categories() as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $f['cat'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="type_id" data-type-select aria-label="Document type"><option value="">All types</option><?php foreach (hrd_types() as $t): ?><option value="<?= (int) $t['id'] ?>" data-cat="<?= (int) $t['category_id'] ?>" <?= $f['type_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="status" aria-label="Status"><option value="">Any status</option><option <?= $f['status'] === 'Active' ? 'selected' : '' ?>>Active</option><option <?= $f['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option></select>
    <button class="btn btn-light border">Filter</button><span class="ms-auto small text-muted"><?= count($rows) ?> template(s)</span></form>
  <?php if (!$rows): empty_state('ri-file-copy-2-line', 'No saved templates', 'Upload your Word templates once; they stay here for every future document.', $canManage ? '<a class="btn btn-primary" href="' . e(url('hrdocs', ['a' => 'tnew'])) . '">Upload template</a>' : ''); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="hr-templates"><thead><tr><th>Template</th><th>Category / type</th><th>Applies to</th><th>Version</th><th>Format</th><th>Status</th><th>Uploaded</th><th>Updated</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $u = url('hrdocs', ['a' => 'template', 'id' => $r['id']]); ?>
      <tr><td><a class="fw-semibold" href="<?= e($u) ?>"><?= e($r['name']) ?></a><?= $r['subcategory'] ? '<div class="small text-muted">' . e($r['subcategory']) . '</div>' : '' ?></td>
        <td class="small"><?= e($r['type_name']) ?><div class="text-muted"><?= e($r['category']) ?></div></td><td class="small"><?= e(HR_APPLIES_TO[$r['applies_to']] ?? $r['applies_to']) ?></td>
        <td>v<?= (int) $r['current_version'] ?></td><td class="small"><?= e(strtoupper((string) ($r['file_format'] ?: 'html'))) ?></td>
        <td><?= badge($r['status']) ?><?= $r['approval_status'] === 'Pending' ? ' <span class="pill pill-warning">Approval</span>' : '' ?></td>
        <td class="small text-nowrap"><?= e($r['creator'] ?? '—') ?><div class="text-muted"><?= fdate($r['created_at']) ?></div></td><td class="small text-nowrap"><?= time_ago($r['updated_at'] ?: $r['created_at']) ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-light border" href="<?= e($u) ?>">Preview / edit</a><?php if ($canManage && $r['status'] === 'Active'): ?> <a class="btn btn-sm btn-primary" href="<?= e(url('hrdocs', ['a' => 'new', 'template_id' => $r['id']])) ?>">Use</a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<?php
page_end(['js/hrdocs.js']);
