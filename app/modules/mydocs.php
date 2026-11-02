<?php
/**
 * Induction & Documents — the employee's onboarding dashboard (and a candidate's shared documents before activation).
 *   index         profile summary, Employee ID, official e-mail, progress, Induction & Policies, documents shared with me
 *   item (id)     one induction item: read / download, mandatory acknowledgement (checkbox unchecked by default)     ack (POST)
 *   doc (id)      a document HR shared with me: read, download if allowed, acknowledge / accept / decline         respond (POST)
 *   ifile, dfile  files, streamed only to the person they are assigned / shared to
 * Everyone sees only their own records: every query is tied to the signed-in login / employee.
 */

$u = user();
$empId = !is_external() ? my_employee_id() : null;
if (!$empId && !is_candidate()) authorize('__never__');
$a = $_GET['a'] ?? 'index';
$self = url('mydocs');

/** An induction item assigned to me, or 404 (never another employee's view). */
function my_item(int $id, ?int $empId): array
{
    if (!$empId) not_found();
    foreach (ind_items_for($empId) as $i) if ((int) $i['id'] === $id) return ind_item($id) + ['__v' => ind_version($i)];
    not_found();
}

function my_doc(int $id): array
{
    $d = hrd_doc($id);
    if (!$d || !hrd_is_recipient($d) || !hrd_doc_can($d, 'view')) not_found();
    return $d;
}

/* ── Acknowledge an induction item / policy (own record only) ── */
if ($a === 'ack' && is_post()) {
    $i = my_item(int_input('id'), $empId);
    try {
        ind_ack((int) $empId, $i, !empty($_POST['confirm']));
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
        redirect(url('mydocs', ['a' => 'item', 'id' => $i['id']]));
    }
    $s = ind_status((int) $empId);
    flash('success', $s['complete'] ? '<strong>Induction complete.</strong> Thank you, all mandatory items are done.' : 'Recorded. ' . ($s['mandatory'] - $s['done']) . ' mandatory item(s) left.');
    redirect($self);
}

/* ── Files: only for items assigned to me / documents shared with me ── */
if ($a === 'ifile') {
    $i = my_item(int_input('id'), $empId);
    $file = $i['kind'] === 'file' ? $i['file'] : ($i['kind'] === 'template' && ($t = hrd_template((int) $i['template_id'])) ? $t['file'] : null);
    if (!$file || !is_file(STORAGE_DIR . '/uploads/' . $file)) not_found();
    ind_open((int) $empId, $i);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . ($ext === 'pdf' ? 'inline' : 'attachment') . '; filename="' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', $i['title']), '-') . '.' . $ext . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile(STORAGE_DIR . '/uploads/' . $file);
    exit;
}

if ($a === 'dfile') {
    $d = my_doc(int_input('id'));
    if (!hrd_doc_can($d, 'download')) authorize('__never__');
    hrd_event(['document_id' => $d['id'], 'employee_id' => $d['employee_id']], 'downloaded', 'Recipient');
    header('Cache-Control: private, no-store');
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $d['title']), '-');
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

/* ── Respond to a shared document ── */
if ($a === 'respond' && is_post()) {
    $d = my_doc(int_input('id'));
    $u2 = url('mydocs', ['a' => 'doc', 'id' => $d['id']]);
    try {
        if (!hrd_doc_can($d, 'respond')) throw new RuntimeException('This document no longer accepts a response.');
        $kind = (string) input('kind');
        $note = trim((string) input('note'));
        if ($kind !== 'decline' && empty($_POST['confirm'])) throw new RuntimeException('Tick the confirmation box first.');
        if ($kind === 'decline' && mb_strlen($note) < 3) throw new RuntimeException('Please tell HR why you are declining.');
        hrd_respond($d, $kind, $note);
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
        redirect($u2);
    }
    flash('success', 'Your response has been recorded. HR has been informed.');
    redirect($u2);
}

if ($a === 'doc') {
    $d = my_doc(int_input('id'));
    hrd_mark_viewed($d);
    $d = my_doc((int) $d['id']);
    page_start($d['title'], ['Induction & Documents' => $self, $d['title']], hrd_doc_can($d, 'download') ? '<a class="btn btn-light border" href="' . e(url('mydocs', ['a' => 'dfile', 'id' => $d['id']])) . '"><i class="ri-download-2-line me-1"></i>Download</a>' : ''); ?>
    <div class="grid grid-3">
      <div class="span-2"><div class="card payslip m-0"><div class="card-body p-4 p-md-5"><?= $d['content'] !== null ? '<div class="policy-body">' . $d['content'] . '</div>' : '<p class="text-muted">This document is a PDF. Use Download to open it.</p>' ?></div></div></div>
      <div class="vstack gap-3">
        <div class="card card-body small"><div class="text-muted"><?= e($d['type_name'] ?? 'Document') ?> · version <?= (int) $d['version'] ?></div><div class="mt-1"><?= badge($d['status']) ?></div>
          <div class="mt-2">Shared <?= fdate($d['shared_at'], 'd M Y') ?><?= $d['expires_at'] ? ' · respond by ' . fdate($d['expires_at'], 'd M Y') : '' ?></div>
          <?= $d['responded_at'] ? '<div class="mt-1">Your response: ' . e($d['status']) . ' on ' . fdate($d['responded_at'], 'd M Y, h:i A') . '</div>' : '' ?></div>
        <?php if (hrd_doc_can($d, 'respond')): ?>
          <?php if ($d['response'] === 'acknowledge'): ?>
            <form class="card card-body vstack gap-2" method="post" action="<?= e(url('mydocs', ['a' => 'respond'])) ?>" data-ack-form><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="kind" value="acknowledge">
              <label class="form-check small"><input class="form-check-input" type="checkbox" name="confirm" value="1" required> <span class="form-check-label"><?= e(HR_DOC_ACK_LABEL) ?></span></label>
              <button class="btn btn-primary" data-ack-submit disabled>Submit acknowledgement</button></form>
          <?php else: ?>
            <form class="card card-body vstack gap-2" method="post" action="<?= e(url('mydocs', ['a' => 'respond'])) ?>" data-ack-form><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="kind" value="accept">
              <label class="form-check small"><input class="form-check-input" type="checkbox" name="confirm" value="1" required> <span class="form-check-label">I have read this document and accept it.</span></label>
              <button class="btn btn-success" data-ack-submit disabled>Accept</button></form>
            <form class="card card-body vstack gap-2" method="post" action="<?= e(url('mydocs', ['a' => 'respond'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="kind" value="decline">
              <textarea class="form-control form-control-sm" name="note" rows="2" maxlength="500" placeholder="Reason for declining" required aria-label="Reason for declining"></textarea><button class="btn btn-outline-danger btn-sm">Decline</button></form>
          <?php endif; ?>
          <div class="small text-muted">Your response is recorded with your signed-in account, the time and your IP address. It is a confirmation, not an electronic signature.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page_end(['js/hrdocs-portal.js']);
    return;
}

if ($a === 'item') {
    $i = my_item(int_input('id'), $empId);
    ind_open((int) $empId, $i);
    $st = array_values(array_filter(ind_status((int) $empId)['items'], fn($x) => (int) $x['id'] === (int) $i['id']))[0] ?? null;
    page_start($i['title'], ['Induction & Documents' => $self, $i['title']]);
    $tpl = $i['kind'] === 'template' ? hrd_template((int) $i['template_id']) : null; ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card payslip m-0"><div class="card-body p-4 p-md-5">
          <?php if ($i['kind'] === 'content'): ?><div class="policy-body"><?= policy_html($i['body']) ?></div>
          <?php elseif ($i['kind'] === 'policy'): ?><h4 class="fw-bold"><?= e($i['policy_title']) ?> <small class="text-muted">v<?= e((string) $i['policy_version']) ?></small></h4><div class="policy-body"><?= policy_html($i['policy_content']) ?></div>
            <?php if ($i['policy_attachment']): ?><a class="btn btn-light border" target="_blank" rel="noopener" href="<?= e(file_url($i['policy_attachment'])) ?>"><i class="ri-attachment-2 me-1"></i>Open the policy document</a><?php endif; ?>
          <?php elseif ($tpl && $tpl['content'] !== null): ?><div class="policy-body"><?= $tpl['content'] ?></div>
          <?php elseif ($i['kind'] === 'link'): ?><p>This item is on an external site.</p><a class="btn btn-primary" href="<?= e($i['url']) ?>" target="_blank" rel="noopener noreferrer"><i class="ri-external-link-line me-1"></i>Open link</a>
          <?php else: ?><p>Open the document to read it.</p><a class="btn btn-primary" href="<?= e(url('mydocs', ['a' => 'ifile', 'id' => $i['id']])) ?>" target="_blank" rel="noopener"><i class="ri-file-text-line me-1"></i>Open / download</a><?php endif; ?>
        </div></div>
      </div>
      <div class="vstack gap-3">
        <div class="card card-body small"><div><?= $i['mandatory'] ? '<span class="pill pill-warning">Mandatory</span>' : '<span class="pill pill-neutral">Optional</span>' ?> <span class="text-muted">version <?= e($i['__v']) ?></span></div>
          <?php if ($st && $st['due']): ?><div class="mt-1">Due <?= fdate($st['due']) ?><?= !$st['done_at'] && $st['due'] < date('Y-m-d') ? ' <span class="pill pill-danger">Overdue</span>' : '' ?></div><?php endif; ?>
          <?php if ($st && $st['older_ack']): ?><div class="mt-1 text-warning">Updated since you last acknowledged it — please review and acknowledge this version.</div><?php endif; ?></div>
        <?php if ($st && $st['done_at']): ?>
          <div class="card card-body"><div class="text-success fw-semibold"><i class="ri-checkbox-circle-fill me-1"></i><?= $i['requires_ack'] ? 'Acknowledged' : 'Completed' ?></div><div class="small text-muted"><?= fdate($st['done_at'], 'd M Y, h:i A') ?></div></div>
        <?php else: ?>
          <form class="card card-body vstack gap-2" method="post" action="<?= e(url('mydocs', ['a' => 'ack'])) ?>" data-ack-form><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
            <label class="form-check small"><input class="form-check-input" type="checkbox" name="confirm" value="1" required> <span class="form-check-label"><?= e($i['requires_ack'] ? POLICY_ACK_LABEL : 'I have gone through this induction item.') ?></span></label>
            <button class="btn btn-primary" data-ack-submit disabled><?= $i['requires_ack'] ? 'Submit acknowledgement' : 'Mark as completed' ?></button>
            <div class="small text-muted">Opening or downloading does not count as acknowledging. This is recorded with your account, the version and the time; it is not an electronic signature.</div></form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page_end(['js/hrdocs-portal.js']);
    return;
}

/* ── Dashboard ── */
$emp = $empId ? one('SELECT e.*, d.name dept, g.title des FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE e.id = ?', [$empId]) : null;
$ind = $empId ? ind_status($empId) : ['items' => [], 'mandatory' => 0, 'done' => 0, 'complete' => true, 'percent' => 100];
$docs = all("SELECT d.*, ty.name type_name FROM hrm_hr_documents d LEFT JOIN hrm_hr_doc_types ty ON ty.id = d.type_id
             WHERE (d.recipient_login_id = ? OR (? > 0 AND d.employee_id = ? AND d.recipient_login_id IS NOT NULL)) AND d.status IN ('Shared','Viewed','Acknowledged','Accepted','Declined','Expired') ORDER BY d.shared_at DESC", [$u['id'], (int) $empId, (int) $empId]);
$pendingDocs = array_filter($docs, fn($d) => in_array($d['status'], HR_DOC_OPEN, true) && $d['response'] !== 'none');
$policiesLeft = $empId ? count(policies_pending($empId)) : 0;
$onb = is_candidate() ? onb_for_login((int) $u['id']) : null;
$steps = $ind['mandatory'] + count($pendingDocs);
$doneSteps = $ind['done'];
$pct = ($ind['mandatory'] + count($pendingDocs)) ? (int) round($doneSteps * 100 / ($ind['mandatory'] + count($pendingDocs))) : 100;
page_start('Induction & Documents', ['Induction & Documents']); ?>
<?php if ($emp && $ind['items'] && $ind['complete'] && !$pendingDocs): ?><div class="alert alert-success"><i class="ri-checkbox-circle-fill me-1"></i><strong>All done.</strong> Your induction and every mandatory acknowledgement are complete. Thank you!</div><?php endif; ?>
<div class="grid grid-3 mb-3">
  <div class="card card-body span-2"><div class="d-flex gap-3 align-items-center flex-wrap">
    <?= avatar($emp ? emp_name($emp) : $u['display_name'], $emp['photo'] ?? null, 'lg') ?>
    <div class="grow"><h5 class="fw-bold m-0"><?= e($emp ? emp_name($emp) : $u['display_name']) ?></h5>
      <?php if ($emp): ?><div class="small text-muted"><?= e($emp['des'] ?: '—') ?><?= $emp['dept'] ? ' · ' . e($emp['dept']) : '' ?> · joined <?= fdate($emp['join_date']) ?></div>
        <div class="small mt-1"><span class="me-3"><span class="text-muted">Employee ID</span> <strong><?= e($emp['emp_code']) ?></strong></span>
          <span><span class="text-muted">Official e-mail</span> <?= $emp['official_email_status'] === 'Provisioned' ? '<strong>' . e($emp['official_email']) . '</strong>' : '<span class="pill pill-warning">Being set up</span>' ?></span></div>
      <?php else: ?><div class="small text-muted">Candidate · documents shared with you by HR appear here</div><?php endif; ?></div>
  </div></div>
  <div class="card card-body"><div class="small text-muted mb-1">Onboarding progress</div><div class="d-flex align-items-end gap-2"><strong class="fs-3"><?= $pct ?>%</strong><span class="small text-muted mb-1"><?= $doneSteps ?> of <?= $steps ?> required step(s)</span></div>
    <div class="progress mt-2" style="height:8px" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Onboarding progress"><div class="progress-bar <?= $pct === 100 ? 'bg-success' : '' ?>" style="width:<?= $pct ?>%"></div></div>
    <?php if ($policiesLeft): ?><div class="small mt-2"><a href="<?= e(url('policies')) ?>"><?= $policiesLeft ?> HR polic<?= $policiesLeft === 1 ? 'y' : 'ies' ?> to acknowledge</a></div><?php endif; ?></div>
</div>
<?php if ($onb): ?><a class="action-banner mb-3" href="<?= e(url('onboarding', ['a' => 'me'])) ?>"><i class="ri-user-received-2-line"></i><div class="grow"><strong>Your offer and joining formalities</strong><div class="small">Offer letter, personal details, documents and e-signatures.</div></div><span class="btn btn-sm btn-primary">Open</span></a><?php endif; ?>
<?php if ($emp): ?>
<div class="card mb-3"><div class="card-header-lite"><span><i class="ri-book-open-line me-1 text-primary"></i>Induction &amp; Policies</span><span class="small text-muted fw-normal"><?= $ind['done'] ?> / <?= $ind['mandatory'] ?> mandatory done</span></div>
  <?php foreach ($ind['items'] as $i): $late = !$i['done_at'] && $i['due'] && $i['due'] < date('Y-m-d'); ?>
    <a class="list-row text-body" href="<?= e(url('mydocs', ['a' => 'item', 'id' => $i['id']])) ?>">
      <span class="onb-doc-icon <?= $i['done_at'] ? 'has' : '' ?>"><i class="<?= $i['done_at'] ? 'ri-checkbox-circle-fill' : ($i['kind'] === 'policy' ? 'ri-shield-check-line' : 'ri-book-open-line') ?>"></i></span>
      <div class="grow"><div class="title"><?= e($i['title']) ?> <?= $i['mandatory'] ? '' : '<span class="pill pill-neutral">Optional</span>' ?></div>
        <div class="sub"><?= $i['done_at'] ? ($i['requires_ack'] ? 'Acknowledged ' : 'Completed ') . fdate($i['done_at'], 'd M Y') : ($i['older_ack'] ? 'Updated — acknowledge the new version' : ($i['opened_at'] ? 'Opened, not yet acknowledged' : 'Not started')) ?><?= $i['due'] && !$i['done_at'] ? ' · due ' . fdate($i['due']) : '' ?></div></div>
      <?= $i['done_at'] ? '<span class="pill pill-success">Done</span>' : ($late ? '<span class="pill pill-danger">Overdue</span>' : '<span class="pill pill-warning">Pending</span>') ?></a>
  <?php endforeach; ?>
  <?php if (!$ind['items']): ?><div class="card-body small text-muted">No induction items are assigned to you right now.</div><?php endif; ?>
</div>
<?php endif; ?>
<div class="card"><div class="card-header-lite"><span><i class="ri-folder-shield-2-line me-1 text-primary"></i>Documents shared with me</span><span class="small text-muted fw-normal"><?= count($pendingDocs) ?> awaiting your response</span></div>
  <?php foreach ($docs as $d): ?>
    <a class="list-row text-body" href="<?= e(url('mydocs', ['a' => 'doc', 'id' => $d['id']])) ?>"><span class="onb-doc-icon has"><i class="ri-file-text-line"></i></span>
      <div class="grow"><div class="title"><?= e($d['title']) ?></div><div class="sub"><?= e($d['type_name'] ?? 'Document') ?> · shared <?= fdate($d['shared_at'], 'd M Y') ?><?= $d['expires_at'] && in_array($d['status'], HR_DOC_OPEN, true) ? ' · respond by ' . fdate($d['expires_at'], 'd M Y') : '' ?></div></div><?= badge($d['status']) ?></a>
  <?php endforeach; ?>
  <?php if (!$docs): ?><div class="card-body small text-muted">Nothing shared with you yet.</div><?php endif; ?>
</div>
<?php
page_end();
