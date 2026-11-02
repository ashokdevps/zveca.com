<?php
/**
 * Client Requests (staff side of client onboarding — see app/lib/engagements.php).
 *   index                         all HR / IT requests by status
 *   view (tabs)                   overview · proposal & documents · billing · delivery · discussion · activity
 *   assign, request_info, decline, commercials, send_proposal, external_signed, create_advance, waive,
 *   milestone_add, milestone_invoice, milestone_delete, authorize, message, share, delivered, close     (POST)
 *   templates, template           client NDA / agreements / SOP / proposal templates
 *   retention, purge              data retention of closed requests
 * manage_engagements runs the workflow; approve_engagements gives the internal authorisation and payment waivers.
 */

if (!plan_allows('manage_engagements')) plan_locked_page('manage_engagements');
if (is_external()) redirect(url());
$canManage = can('manage_engagements');
$canApprove = can('approve_engagements');
$a = $_GET['a'] ?? 'index';

function eng_url(array $e, array $p = []): string
{
    return url('engagements', ['a' => 'view', 'id' => $e['id']] + $p);
}

function eng_back(array $e, string $type, string $msg, string $tab = ''): never
{
    flash($type, $msg);
    redirect(eng_url($e, $tab ? ['tab' => $tab] : []));
}

function eng_need(array $e, array $statuses, string $tab = ''): void
{
    if (!in_array($e['status'], $statuses, true)) eng_back($e, 'warning', 'Not possible while the request is <strong>' . e($e['status']) . '</strong>.', $tab);
}

/* ═════════════════════════ Actions ═════════════════════════ */
$postActions = ['assign', 'request_info', 'decline', 'commercials', 'send_proposal', 'external_signed', 'create_advance', 'waive', 'milestone_add', 'milestone_invoice',
    'milestone_delete', 'authorize', 'message', 'share', 'delivered', 'close'];
if (in_array($a, $postActions, true) && is_post()) {
    $e = eng_access(int_input('id'));
    $note = mb_substr(trim((string) input('note', '')), 0, 4000);
    $cid = (int) $e['client_id'];
    if (!in_array($a, ['authorize', 'waive'], true)) authorize('manage_engagements');

    if ($a === 'assign') {
        $owner = int_input('owner_id') ?: null;
        if ($owner && !val("SELECT id FROM it_login WHERE id = ? AND status = 'active' AND LOWER(usertype) NOT IN ('client','vendor','candidate')", [$owner])) eng_back($e, 'danger', 'Choose a staff login.');
        eng_save((int) $e['id'], ['owner_id' => $owner]);
        eng_log($cid, (int) $e['id'], 'assigned', $owner ? login_name($owner) : 'Unassigned', 'staff');
        eng_back($e, 'success', 'Owner updated.');
    }
    if ($a === 'request_info') {
        eng_need($e, ['Submitted']);
        if ($note === '') eng_back($e, 'danger', 'Describe what information is missing.');
        eng_save((int) $e['id'], ['status' => 'Info Requested', 'info_note' => $note, 'info_requested_at' => date('Y-m-d H:i:s')]);
        eng_log($cid, (int) $e['id'], 'info_requested', $note, 'staff');
        eng_notify_client($e, "Information needed: {$e['ref']} " . eng_title($e), "To prepare your proposal we need a little more information:\n\n$note\n\nUpdate your request: " . site_origin() . url('services', ['a' => 'edit', 'id' => $e['id']]));
        eng_back($e, 'success', 'The client has been asked for the missing information.');
    }
    if ($a === 'decline') {
        eng_need($e, ['Submitted', 'Info Requested', 'Proposal Sent']);
        if ($note === '') eng_back($e, 'danger', 'Give the reason (the client sees it).');
        eng_save((int) $e['id'], ['status' => 'Declined', 'closed_at' => date('Y-m-d H:i:s'), 'closure_type' => 'Declined', 'closure_note' => $note]);
        q("UPDATE hrm_client_documents SET status = 'Void' WHERE engagement_id = ? AND status = 'Awaiting Signature'", [$e['id']]);
        eng_log($cid, (int) $e['id'], 'declined', $note, 'staff');
        eng_notify_client($e, "Update on {$e['ref']}", "We are unable to take up your request \"" . eng_title($e) . "\":\n\n$note\n\nThank you for considering us.");
        eng_back($e, 'success', 'Request declined.');
    }
    if ($a === 'commercials') {
        eng_need($e, ['Submitted', 'Info Requested', 'Proposal Sent'], 'proposal');
        $models = ENG_FEE_MODELS[$e['service']];
        $data = ['fee_model' => isset($models[input('fee_model')]) ? input('fee_model') : null, 'fee_value' => is_numeric(input('fee_value')) ? round((float) input('fee_value'), 2) : null,
            'estimate_amount' => is_numeric(input('estimate_amount')) ? round((float) input('estimate_amount'), 2) : null, 'advance_amount' => max(0, round((float) input('advance_amount'), 2)),
            'payment_terms' => mb_substr(trim((string) input('payment_terms')), 0, 300) ?: null, 'replacement_days' => $e['service'] === 'hr' ? (int_input('replacement_days') ?: null) : null,
            'valid_until' => valid_date(input('valid_until')), 'title' => mb_substr(trim((string) input('title')), 0, 190) ?: $e['title']];
        if ($e['service'] === 'it') $data['sop_text'] = mb_substr(trim((string) input('sop_text')), 0, 60000) ?: null;
        eng_save((int) $e['id'], $data);
        eng_log($cid, (int) $e['id'], 'milestone', 'Commercials updated: ' . ($models[$data['fee_model']] ?? '—') . ' · advance ' . money($data['advance_amount']), 'staff');
        eng_back($e, 'success', 'Commercials saved.' . ($e['status'] === 'Proposal Sent' ? ' Click <strong>Send proposal</strong> again to issue the updated documents (changed documents get a new version).' : ''), 'proposal');
    }
    if ($a === 'send_proposal') {
        eng_need($e, ['Submitted', 'Info Requested', 'Proposal Sent'], 'proposal');
        $err = [];
        if (!$e['fee_model'] || (float) $e['fee_value'] <= 0) $err[] = 'Set the fee model and fee.';
        if (!$e['valid_until'] || $e['valid_until'] < date('Y-m-d')) $err[] = 'Set a "valid until" date in the future.';
        if ($e['service'] === 'it' && trim((string) $e['sop_text']) === '') $err[] = 'Write the scope of work (SOP).';
        if (!eng_client_recipients($cid, $e['service'], true)) $err[] = 'This client has no active signatory login for ' . ENG_SERVICES[$e['service']]['label'] . '. Mark a client login as signatory first (Clients → logins).';
        if ($err) eng_back($e, 'danger', implode('<br>', array_map('e', $err)), 'proposal');
        try {
            $pending = eng_issue_documents($e);
        } catch (RuntimeException $ex) {
            eng_back($e, 'danger', e($ex->getMessage()), 'proposal');
        }
        if ($e['advance_invoice_id'] && ($inv = eng_advance_invoice($e)) && $inv['status'] === 'Unpaid' && abs((float) $inv['amount'] - (float) $e['advance_amount']) > 0.009) {
            q("UPDATE hrm_invoices SET status = 'Cancelled', notes = CONCAT(COALESCE(notes,''), ' · Replaced after proposal revision') WHERE id = ?", [$inv['id']]);
            eng_save((int) $e['id'], ['advance_invoice_id' => null]);
        }
        eng_save((int) $e['id'], ['status' => 'Proposal Sent', 'proposal_sent_at' => date('Y-m-d H:i:s')]);
        eng_refresh_status((int) $e['id']);
        eng_log($cid, (int) $e['id'], 'proposal_sent', count($pending) . ' document(s) to sign: ' . implode(', ', array_column($pending, 'title')), 'staff');
        $list = implode("\n", array_map(fn($d) => '- ' . $d['title'] . ' (version ' . $d['version'] . ')', $pending));
        eng_notify_client($e, "Proposal ready for your approval: {$e['ref']}", "Your proposal for \"" . eng_title($e) . "\" is ready." . ($pending ? "\n\nPlease review and sign:\n$list" : '')
            . "\n\nOpen your request: " . site_origin() . url('services', ['a' => 'view', 'id' => $e['id']]) . "\n\nOnly your company's authorised signatory can sign; we will e-mail a one-time code at the time of signing.", 'signature');
        eng_back($e, 'success', $pending ? 'Proposal sent. ' . count($pending) . ' document(s) are waiting for the client\'s signature.' : 'Proposal sent. All documents were already signed.', 'proposal');
    }
    if ($a === 'external_signed') {
        $d = one("SELECT * FROM hrm_client_documents WHERE id = ? AND client_id = ? AND status = 'Awaiting Signature'", [int_input('doc'), $cid]) ?? not_found();
        $name = mb_substr(trim((string) input('signer_name')), 0, 150);
        $provider = mb_substr(trim((string) input('provider')), 0, 60);
        try {
            $file = store_upload('executed', 'clientdocs', 15);
            if (!$file || !str_ends_with($file, '.pdf')) throw new RuntimeException('Upload the executed copy as a PDF.');
            if ($name === '' || $provider === '') throw new RuntimeException('Enter the signer\'s name and the e-sign provider / reference.');
        } catch (RuntimeException $ex) {
            eng_back($e, 'danger', e($ex->getMessage()), 'proposal');
        }
        update('hrm_client_documents', ['status' => 'Signed', 'file' => $file, 'original_name' => mb_substr(basename((string) $_FILES['executed']['name']), 0, 190), 'updated_at' => date('Y-m-d H:i:s'),
            'review_note' => "Executed via $provider"], (int) $d['id']);
        insert('hrm_client_signatures', ['document_id' => $d['id'], 'party' => 'client', 'login_id' => null, 'signer_name' => $name, 'signer_designation' => mb_substr(trim((string) input('signer_designation')), 0, 120) ?: null,
            'method' => 'external', 'content_hash' => hash_file('sha256', STORAGE_DIR . '/uploads/' . $file), 'ip' => client_ip(), 'user_agent' => 'Uploaded by ' . user()['display_name'] . " · $provider", 'signed_at' => date('Y-m-d H:i:s')]);
        eng_log($cid, (int) $e['id'], 'external_signed', "{$d['title']} v{$d['version']} · $provider · $name", 'staff');
        if ($d['kind'] === 'proposal' && (float) $e['advance_amount'] > 0 && !$e['payment_waived'] && !$e['advance_invoice_id']) {
            eng_save((int) $e['id'], ['advance_invoice_id' => eng_create_invoice($e, 'Advance for ' . $e['ref'] . ' — ' . eng_title($e), (float) $e['advance_amount'])]);
        }
        $d['engagement_id'] ? eng_refresh_status((int) $e['id']) : eng_refresh_client($cid);
        eng_back($e, 'success', e($d['title']) . ' marked signed with the executed copy.', 'proposal');
    }
    if ($a === 'create_advance') {
        if ((float) $e['advance_amount'] <= 0 || $e['advance_invoice_id']) eng_back($e, 'info', 'No advance invoice needed.', 'billing');
        eng_save((int) $e['id'], ['advance_invoice_id' => eng_create_invoice($e, 'Advance for ' . $e['ref'] . ' — ' . eng_title($e), (float) $e['advance_amount'])]);
        eng_refresh_status((int) $e['id']);
        eng_back($e, 'success', 'Advance invoice issued and e-mailed to the client.', 'billing');
    }
    if ($a === 'waive') {
        authorize('approve_engagements');
        eng_need($e, ENG_PROPOSAL_STAGE, 'billing');
        if ($note === '') eng_back($e, 'danger', 'Record why the advance is waived (it is kept in the audit log).', 'billing');
        eng_save((int) $e['id'], ['payment_waived' => 1, 'waiver_note' => mb_substr($note, 0, 500)]);
        eng_log($cid, (int) $e['id'], 'payment_waived', $note, 'staff');
        eng_refresh_status((int) $e['id']);
        eng_back($e, 'success', 'Advance payment waived.', 'billing');
    }
    if ($a === 'milestone_add') {
        $title = mb_substr(trim((string) input('title')), 0, 190);
        $amount = round((float) input('amount'), 2);
        if ($title === '' || $amount <= 0) eng_back($e, 'danger', 'Enter the milestone and its amount.', 'billing');
        insert('hrm_engagement_milestones', ['engagement_id' => $e['id'], 'title' => $title, 'due_date' => valid_date(input('due_date')), 'amount' => $amount,
            'sort' => (int) val('SELECT COUNT(*) FROM hrm_engagement_milestones WHERE engagement_id = ?', [$e['id']])]);
        eng_log($cid, (int) $e['id'], 'milestone', "Added: $title · " . money($amount), 'staff');
        eng_back($e, 'success', 'Milestone added.', 'billing');
    }
    if ($a === 'milestone_invoice' || $a === 'milestone_delete') {
        $m = one('SELECT * FROM hrm_engagement_milestones WHERE id = ? AND engagement_id = ?', [int_input('milestone'), $e['id']]) ?? not_found();
        if ($m['status'] !== 'Planned') eng_back($e, 'warning', 'This milestone is already invoiced.', 'billing');
        if ($a === 'milestone_delete') {
            q('DELETE FROM hrm_engagement_milestones WHERE id = ?', [$m['id']]);
            eng_back($e, 'success', 'Milestone removed.', 'billing');
        }
        eng_create_invoice($e, $m['title'] . ' — ' . $e['ref'] . ' ' . eng_title($e), (float) $m['amount'], (int) $m['id']);
        eng_back($e, 'success', 'Invoice issued for the milestone and e-mailed to the client.', 'billing');
    }
    if ($a === 'authorize') {
        authorize('approve_engagements');
        $g = eng_gates($e);
        if (!in_array($e['status'], ENG_PROPOSAL_STAGE, true) || !$g['proposal'][1] || !$g['documents'][1] || !$g['payment'][1]) {
            eng_back($e, 'danger', 'Authorisation needs the proposal accepted, every agreement signed and the payment condition met first.');
        }
        $mgr = int_input('manager_id') ?: null;
        db()->beginTransaction();
        try {
            eng_save((int) $e['id'], ['authorized_by' => user()['id'], 'authorized_at' => date('Y-m-d H:i:s'), 'authorization_note' => mb_substr($note, 0, 500) ?: null]);
            [$kind, $newId] = eng_activate(eng_find((int) $e['id']), $mgr);
            // Countersignature: the company accepts every document the client signed for this request (and its company agreements).
            foreach (eng_required_docs($e) as $d) {
                if ($d && $d['status'] === 'Signed' && !val("SELECT id FROM hrm_client_signatures WHERE document_id = ? AND party = 'company'", [$d['id']])) {
                    insert('hrm_client_signatures', ['document_id' => $d['id'], 'party' => 'company', 'login_id' => user()['id'], 'signer_name' => user()['display_name'],
                        'signer_designation' => user()['employee'] ? (val('SELECT title FROM hrm_designations WHERE id = ?', [user()['employee']['designation_id']]) ?: 'Authorised signatory') : 'Authorised signatory',
                        'signer_email' => user()['email'], 'method' => 'internal', 'content_hash' => $d['content_hash'] ?: hash('sha256', (string) $d['content']), 'ip' => client_ip(),
                        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'signed_at' => date('Y-m-d H:i:s')]);
                }
            }
            eng_save((int) $e['id'], ['status' => 'Active', 'activated_at' => date('Y-m-d H:i:s')]);
            q("UPDATE hrm_clients SET status = 'Active' WHERE id = ? AND status = 'Prospect'", [$cid]);
            db()->commit();
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            eng_back($e, 'danger', 'Activation failed: ' . e($ex->getMessage()));
        }
        eng_log($cid, (int) $e['id'], 'authorized', $note ?: null, 'staff');
        eng_log($cid, (int) $e['id'], 'activated', ($kind === 'job' ? 'Vacancy #' : 'Project #') . $newId, 'system');
        eng_notify_client($e, "Activated: {$e['ref']} " . eng_title($e), ($e['service'] === 'hr'
            ? "Your vacancy \"" . eng_title($e) . "\" is now active. Our recruitment team has started sourcing; you'll see candidates and interview updates in your portal."
            : "Your project \"" . eng_title($e) . "\" is now active. You can follow tasks, reports, milestones and deliverables in your portal.") . "\n\n" . site_origin() . url('services', ['a' => 'view', 'id' => $e['id']]));
        eng_back($e, 'success', 'Authorised. The ' . ($kind === 'job' ? '<a href="' . e(url('jobs', ['a' => 'view', 'id' => $newId])) . '">vacancy</a>' : '<a href="' . e(url('projects', ['a' => 'view', 'id' => $newId])) . '">project</a>') . ' is live.', 'delivery');
    }
    if ($a === 'message') {
        $kind = in_array(input('kind'), ['comment', 'internal', 'report', 'meeting'], true) ? input('kind') : 'comment';
        if ($note === '') eng_back($e, 'danger', 'Write the message.', 'discussion');
        $at = $kind === 'meeting' && input('meeting_at') ? date('Y-m-d H:i:s', strtotime((string) input('meeting_at'))) : null;
        $link = trim((string) input('meeting_link'));
        if ($kind === 'meeting' && !$at) eng_back($e, 'danger', 'Enter the meeting date and time.', 'discussion');
        if ($link !== '' && !preg_match('#^https://#i', $link)) eng_back($e, 'danger', 'The meeting link must start with https://', 'discussion');
        try { $file = store_upload('attachment', 'clientdocs', 15); } catch (RuntimeException $ex) { eng_back($e, 'danger', e($ex->getMessage()), 'discussion'); }
        insert('hrm_engagement_messages', ['engagement_id' => $e['id'], 'login_id' => user()['id'], 'kind' => $kind === 'internal' ? 'comment' : $kind, 'body' => $note,
            'internal' => $kind === 'internal' ? 1 : 0, 'meeting_at' => $at, 'meeting_link' => $link ?: null, 'attachment' => $file]);
        if ($kind !== 'internal') {
            eng_log($cid, (int) $e['id'], $kind === 'comment' ? 'comment' : $kind, mb_strimwidth($note, 0, 120, '…'), 'staff');
            $subject = ['comment' => 'New message', 'report' => 'Status report', 'meeting' => 'Meeting scheduled'][$kind] . ": {$e['ref']} " . eng_title($e);
            eng_notify_client($e, $subject, ($kind === 'meeting' ? 'Meeting: ' . fdate($at, 'l, d M Y, h:i A') . ($link ? "\nJoin: $link" : '') . "\n\n" : '') . $note . "\n\nOpen in the portal: " . site_origin() . url('services', ['a' => 'view', 'id' => $e['id']]) . '#discussion');
        }
        eng_back($e, 'success', $kind === 'internal' ? 'Internal note saved (not visible to the client).' : 'Posted and e-mailed to the client.', 'discussion');
    }
    if ($a === 'share') {
        eng_need($e, ['Active', 'Delivered'], 'delivery');
        $title = mb_substr(trim((string) input('title')), 0, 190);
        try {
            $file = store_upload('file', 'clientdocs', 25);
            if (!$file || $title === '') throw new RuntimeException('Give a title and choose the file.');
        } catch (RuntimeException $ex) {
            eng_back($e, 'danger', e($ex->getMessage()), 'delivery');
        }
        $kind = input('type') === 'deliverable' ? 'deliverable' : 'file';
        $ver = 1 + (int) val("SELECT MAX(version) FROM hrm_client_documents WHERE engagement_id = ? AND kind = ? AND title = ?", [$e['id'], $kind, $title]);
        insert('hrm_client_documents', ['client_id' => $cid, 'engagement_id' => $e['id'], 'kind' => $kind, 'title' => $title, 'version' => $ver, 'file' => $file,
            'original_name' => mb_substr(basename((string) $_FILES['file']['name']), 0, 190), 'content_hash' => hash_file('sha256', STORAGE_DIR . '/uploads/' . $file),
            'status' => $kind === 'deliverable' ? 'Shared' : 'Signed', 'requires_signature' => 0, 'review_note' => $note ?: null, 'created_by' => user()['id']]);
        if ($kind === 'file') q("UPDATE hrm_client_documents SET status = 'Shared' WHERE engagement_id = ? AND kind = 'file' AND title = ? AND version = ?", [$e['id'], $title, $ver]);
        eng_log($cid, (int) $e['id'], 'deliverable', "$title v$ver", 'staff');
        eng_notify_client($e, ($kind === 'deliverable' ? 'Deliverable ready for review' : 'New file shared') . ": $title", ($kind === 'deliverable' ? "\"$title\" (version $ver) is ready for your review. Please accept it or request changes in the portal within 7 working days." : "We shared \"$title\" with you.")
            . ($note ? "\n\n$note" : '') . "\n\n" . site_origin() . url('services', ['a' => 'view', 'id' => $e['id']]) . '#delivery');
        eng_back($e, 'success', 'Shared with the client.', 'delivery');
    }
    if ($a === 'delivered') {
        eng_need($e, ['Active'], 'delivery');
        eng_save((int) $e['id'], ['status' => 'Delivered', 'delivered_at' => date('Y-m-d H:i:s')]);
        eng_log($cid, (int) $e['id'], 'delivered', $note ?: null, 'staff');
        eng_notify_client($e, "Delivered: {$e['ref']} " . eng_title($e), ($e['service'] === 'hr' ? 'All positions for this vacancy have been filled.' : 'The project has been delivered.') . ($note ? "\n\n$note" : '') . "\n\nWe would love your feedback: " . site_origin() . url('services', ['a' => 'view', 'id' => $e['id']]));
        eng_back($e, 'success', 'Marked delivered. The client was asked for feedback.', 'delivery');
    }
    if ($a === 'close') {
        eng_need($e, ['Active', 'Delivered', 'Awaiting Authorization', 'Awaiting Payment', 'Proposal Sent'], 'delivery');
        $type = input('closure_type') === 'Cancelled' ? 'Cancelled' : 'Completed';
        $unpaid = (float) val("SELECT COALESCE(SUM(total - paid), 0) FROM hrm_invoices WHERE engagement_id = ? AND status IN ('Unpaid','Partially Paid')", [$e['id']]);
        eng_save((int) $e['id'], ['status' => 'Closed', 'closed_at' => date('Y-m-d H:i:s'), 'closure_type' => $type, 'closure_note' => $note ?: null]);
        if ($e['job_id']) q("UPDATE hrm_jobs SET status = 'Closed' WHERE id = ?", [$e['job_id']]);
        if ($e['project_id']) q('UPDATE hrm_projects SET status = ? WHERE id = ?', [$type === 'Completed' ? 'Completed' : 'Cancelled', $e['project_id']]);
        q("UPDATE hrm_client_documents SET status = 'Void' WHERE engagement_id = ? AND status = 'Awaiting Signature'", [$e['id']]);
        eng_log($cid, (int) $e['id'], 'closed', $type . ($note ? ": $note" : '') . ($unpaid > 0 ? ' · ' . money($unpaid, true) . ' outstanding' : ''), 'staff');
        eng_notify_client($e, "Closed: {$e['ref']} " . eng_title($e), 'This ' . strtolower(ENG_SERVICES[$e['service']]['output']) . ' has been closed (' . strtolower($type) . ').' . ($note ? "\n\n$note" : '') . ($unpaid > 0 ? "\n\nOutstanding amount: " . money($unpaid, true) . '.' : ''));
        eng_back($e, 'success', 'Closed.' . ($unpaid > 0 ? ' Note: ' . money($unpaid, true) . ' is still outstanding on its invoices.' : ''));
    }
}

/* ═════════════════════════ Templates ═════════════════════════ */
if ($a === 'template') {
    authorize('manage_engagements');
    $t = int_input('id') ? (one("SELECT * FROM hrm_onboarding_templates WHERE id = ? AND kind LIKE 'client%'", [int_input('id')]) ?? not_found()) : null;
    $kind = $t['kind'] ?? (isset(ENG_TEMPLATE_KINDS[input('kind')]) ? input('kind') : 'client_nda');
    if (is_post()) {
        $name = mb_substr(trim((string) input('name')), 0, 150);
        $body = trim((string) input('body'));
        if ($name === '' || mb_strlen($body) < 50) {
            $_SESSION['_old'] = $_POST;
            flash('danger', 'Give the template a name and its text.');
            redirect(url('engagements', ['a' => 'template', 'id' => $t['id'] ?? null, 'kind' => $kind]));
        }
        $data = ['kind' => $kind, 'name' => $name, 'body' => $body, 'active' => !empty($_POST['active']) ? 1 : 0, 'is_default' => 1, 'updated_by' => user()['id']];
        q('UPDATE hrm_onboarding_templates SET is_default = 0 WHERE kind = ?', [$kind]);
        if ($t) {
            // A changed text is a new version: clients who signed the old one are asked to sign again with their next proposal.
            if ($body !== $t['body']) $data['version'] = (int) $t['version'] + 1;
            update('hrm_onboarding_templates', $data, (int) $t['id']);
        } else {
            insert('hrm_onboarding_templates', $data);
        }
        log_activity($t ? 'updated' : 'created', 'client template', (int) ($t['id'] ?? 0), $name);
        flash('success', 'Template saved' . (isset($data['version']) ? ' as version ' . $data['version'] . '. Clients who signed an older version will be asked to sign this one with their next proposal' : '') . '.');
        redirect(url('engagements', ['a' => 'templates']));
    }
    page_start($t ? 'Edit template' : 'New template', ['Client Requests' => url('engagements'), 'Templates' => url('engagements', ['a' => 'templates']), $t['name'] ?? ENG_TEMPLATE_KINDS[$kind]]);
    $ph = ['client_company', 'client_address', 'client_gstin', 'client_contact', 'client_email', 'engagement_ref', 'engagement_title', 'service', 'requirement_summary', 'commercials_table', 'sop_scope',
        'start_date', 'deadline', 'replacement_days', 'payment_terms', 'validity_date', 'advance_amount', 'company_name', 'company_address', 'company_gstin', 'jurisdiction', 'today']; ?>
    <form method="post" class="grid grid-3"><?= csrf_field() ?>
      <div class="span-2 vstack gap-3">
        <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-file-text-line"></i></span><div><h6><?= e(ENG_TEMPLATE_KINDS[$kind]) ?></h6><small>The active template is used for every new document of this kind. Signed documents never change.</small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?= form_field('name', ['label' => 'Name', 'required' => true, 'col' => 'col-md-8'], old('name', $t['name'] ?? ENG_TEMPLATE_KINDS[$kind])) ?>
            <div class="col-md-4 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" <?= old('active', $t['active'] ?? 1) ? 'checked' : '' ?>> <span class="form-check-label">Active</span></label></div>
            <div class="col-12"><label class="form-label" for="f_body">Text</label><textarea class="form-control font-monospace small" id="f_body" name="body" rows="26" required><?= e(old('body', $t['body'] ?? '')) ?></textarea>
              <div class="form-text"><code>## Heading</code>, <code>- list</code>, <code>1. numbered</code>, <code>**bold**</code>; blank line = new paragraph.</div></div>
          </div></div></section>
        <div class="form-actions sticky-actions"><a class="btn btn-light border" href="<?= e(url('engagements', ['a' => 'templates'])) ?>">Cancel</a><button class="btn btn-primary ms-auto">Save template</button></div>
      </div>
      <div class="card card-body small align-self-start"><h6 class="fw-bold">Placeholders</h6>
        <div class="d-flex flex-wrap gap-1"><?php foreach ($ph as $p): ?><button type="button" class="tag border-0" data-insert="{{<?= $p ?>}}">{{<?= $p ?>}}</button><?php endforeach; ?></div>
        <?php if ($t): ?><p class="text-muted mt-3 mb-0">Version <?= (int) $t['version'] ?></p><?php endif; ?>
        <p class="text-muted mt-3 mb-0"><i class="ri-scales-3-line"></i> Starter texts are general drafts. Have the NDA, agreements, privacy notice and signing process reviewed by your legal adviser (Indian law and any foreign jurisdiction of your clients).</p></div>
    </form>
    <?php
    page_end(['js/onboarding.js']);
    return;
}
if ($a === 'templates') {
    authorize('manage_engagements');
    $rows = all("SELECT * FROM hrm_onboarding_templates WHERE kind LIKE 'client%' ORDER BY FIELD(kind,'client_nda','client_hr_agreement','client_it_agreement','client_sop','client_proposal'), is_default DESC");
    page_start('Client document templates', ['Client Requests' => url('engagements'), 'Templates']); ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Template</th><th>Document</th><th>Used for</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr class="row-link" data-href="<?= e(url('engagements', ['a' => 'template', 'id' => $r['id']])) ?>"><td class="fw-semibold"><?= e($r['name']) ?></td><td><?= e(ENG_TEMPLATE_KINDS[$r['kind']] ?? $r['kind']) ?></td>
        <td class="small"><?= e(['client_nda' => 'HR & IT', 'client_hr_agreement' => 'HR services', 'client_it_agreement' => 'IT services', 'client_sop' => 'IT services (per project)', 'client_proposal' => 'HR & IT (per request)'][$r['kind']] ?? '') ?></td>
        <td><?= (int) $r['version'] ?></td><td><?= badge($r['active'] ? 'Active' : 'Inactive') ?></td><td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(url('engagements', ['a' => 'template', 'id' => $r['id']])) ?>">Edit</a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
    <?php
    page_end();
    return;
}

/* ═════════════════════════ Data retention ═════════════════════════ */
if ($a === 'retention' || ($a === 'purge' && is_post())) {
    authorize('manage_settings');
    $months = max(12, (int) setting('eng_retention_months', 96));
    $cut = date('Y-m-d H:i:s', strtotime("-$months months"));
    if ($a === 'purge') {
        $e = eng_find(int_input('id')) ?? not_found();
        if (!$e['closed_at'] || $e['closed_at'] > $cut || $e['purged_at']) { flash('danger', 'This request is not past its retention period.'); redirect(url('engagements', ['a' => 'retention'])); }
        $n = 0;
        foreach (all('SELECT id, attachment FROM hrm_engagement_messages WHERE engagement_id = ? AND attachment IS NOT NULL', [$e['id']]) as $m) { @unlink(STORAGE_DIR . '/uploads/' . $m['attachment']); $n++; }
        q('UPDATE hrm_engagement_messages SET attachment = NULL WHERE engagement_id = ?', [$e['id']]);
        foreach (array_merge(...array_values(ENG_FORMS[$e['service']])) as $k => $f) {
            if (($f['type'] ?? '') === 'file' && !empty($e['form_data'][$k])) { @unlink(STORAGE_DIR . '/uploads/' . $e['form_data'][$k]); $e['form_data'][$k] = ''; $n++; }
        }
        eng_save((int) $e['id'], ['form' => $e['form_data'], 'purged_at' => date('Y-m-d H:i:s')]);
        eng_log((int) $e['client_id'], (int) $e['id'], 'purged', "$n attachment(s) removed; signed documents and invoices kept", 'staff');
        flash('success', "$n attachment(s) removed. Signed documents, signature evidence and invoices are kept.");
        redirect(url('engagements', ['a' => 'retention']));
    }
    $rows = all('SELECT e.*, c.name client_name FROM hrm_engagements e JOIN hrm_clients c ON c.id = e.client_id WHERE e.closed_at IS NOT NULL AND e.closed_at <= ? ORDER BY e.closed_at', [$cut]);
    page_start('Data retention', ['Client Requests' => url('engagements'), 'Data retention']); ?>
    <div class="card card-body small mb-3"><i class="ri-information-line"></i> Requests closed more than <strong><?= $months ?> months</strong> ago (Settings → Client onboarding). Removing attachments deletes request files and discussion attachments. Signed agreements, signature evidence and invoices are kept: tax and company law require them for at least 8 years.</div>
    <div class="card"><?php if (!$rows): empty_state('ri-archive-line', 'Nothing past its retention period', 'Closed requests appear here once they pass the retention period.'); else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Request</th><th>Client</th><th>Closed</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr><td><?= e($r['ref']) ?> · <?= e($r['title']) ?></td><td><?= e($r['client_name']) ?></td><td><?= fdate($r['closed_at']) ?></td><td><?= $r['purged_at'] ? 'Attachments removed ' . fdate($r['purged_at']) : badge($r['status']) ?></td>
        <td class="text-end"><?= $r['purged_at'] ? '' : post_button(url('engagements', ['a' => 'purge']), 'Remove attachments', 'btn btn-sm btn-outline-danger', 'Remove the attachments of ' . $r['ref'] . '? This cannot be undone.', ['id' => $r['id']]) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?></div>
    <?php
    page_end();
    return;
}

/* ═════════════════════════ Case page ═════════════════════════ */
if ($a === 'view') {
    $e = eng_access(int_input('id'));
    $tabs = ['overview' => 'Overview', 'proposal' => 'Proposal & documents', 'billing' => 'Billing', 'delivery' => 'Execution & delivery', 'discussion' => 'Discussion', 'activity' => 'Activity'];
    $tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'overview';
    $s = ENG_SERVICES[$e['service']];
    $gates = eng_gates($e);
    $client = one('SELECT * FROM hrm_clients WHERE id = ?', [$e['client_id']]);
    $logins = all("SELECT id, username, email, designation, is_signatory, client_scope, status, last_login FROM it_login WHERE client_id = ? ORDER BY is_signatory DESC, username", [$e['client_id']]);
    $post = fn($act, $label, $class = 'btn btn-light border', $confirm = '', $prompt = '', $fields = []) => str_replace('<button', '<button' . ($prompt ? ' data-prompt="' . e($prompt) . '"' : ''), post_button(url('engagements', ['a' => $act]), $label, $class, $confirm, ['id' => $e['id']] + $fields));
    $btns = [];
    if ($canManage && $e['status'] === 'Submitted') {
        $btns[] = $post('request_info', '<i class="ri-question-answer-line me-1"></i>Request information', 'btn btn-outline-warning', '', 'What information is missing? (sent to the client)');
        $btns[] = '<a class="btn btn-primary" href="' . e(eng_url($e, ['tab' => 'proposal'])) . '"><i class="ri-file-text-line me-1"></i>Prepare proposal</a>';
    }
    if ($canApprove && $e['status'] === 'Awaiting Authorization') $btns[] = '<a class="btn btn-success" href="' . e(eng_url($e, ['tab' => 'overview'])) . '#authorize"><i class="ri-shield-check-line me-1"></i>Authorise &amp; activate</a>';
    $more = [];
    if ($canManage && in_array($e['status'], ['Submitted', 'Info Requested', 'Proposal Sent'], true)) $more[] = $post('decline', '<i class="ri-close-circle-line me-2"></i>Decline request', 'dropdown-item text-danger', '', 'Why are you declining? (the client sees this)');
    if ($e['job_id']) $more[] = '<a class="dropdown-item" href="' . e(url('jobs', ['a' => 'view', 'id' => $e['job_id']])) . '"><i class="ri-briefcase-4-line me-2"></i>Vacancy</a>';
    if ($e['project_id']) $more[] = '<a class="dropdown-item" href="' . e(url('projects', ['a' => 'view', 'id' => $e['project_id']])) . '"><i class="ri-folder-chart-line me-2"></i>Project</a>';
    if (can('manage_accounts')) $more[] = '<a class="dropdown-item" href="' . e(url('clients', ['a' => 'view', 'id' => $e['client_id']])) . '"><i class="ri-building-4-line me-2"></i>Client record</a>';
    if ($more) $btns[] = '<div class="dropdown"><button class="btn btn-light border" data-bs-toggle="dropdown" aria-label="More"><i class="ri-more-2-fill"></i></button><div class="dropdown-menu dropdown-menu-end">' . implode('', $more) . '</div></div>';
    page_start(eng_title($e), ['Client Requests' => url('engagements'), $e['ref'] ?: 'Draft'], implode('', $btns)); ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center mb-3"><span class="eng-svc-icon sm"><i class="<?= $s['icon'] ?>"></i></span>
          <div class="grow"><div class="small text-muted"><?= e($s['label']) ?> · <?= e($e['ref'] ?: 'Draft') ?> · submitted <?= fdate($e['submitted_at']) ?> by <?= e($e['submitter'] ?? '—') ?></div>
            <h5 class="fw-bold m-0"><?= e(eng_title($e)) ?> <?= badge($e['status']) ?></h5><div class="small"><i class="ri-building-4-line"></i> <?= e($e['client_name']) ?> <?= badge($client['status']) ?></div></div>
          <?php if ($canManage): ?>
            <form method="post" action="<?= e(url('engagements', ['a' => 'assign'])) ?>" class="d-flex gap-1 align-items-center"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
              <select class="form-select form-select-sm" name="owner_id" onchange="this.form.submit()" aria-label="Owner"><option value="">Unassigned</option><?php foreach (eng_owner_options() as $k => $v): ?><option value="<?= (int) $k ?>" <?= (int) $e['owner_id'] === (int) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></form>
          <?php endif; ?>
        </div>
        <?= eng_tracker($e) ?>
      </div>
      <nav class="nav-tabs-lite"><?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(eng_url($e, ['tab' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
    </div>

    <?php if ($tab === 'overview'): ?>
      <div class="grid grid-3">
        <div class="span-2 vstack gap-3">
          <?php if ($e['status'] === 'Info Requested'): ?><div class="alert alert-warning small mb-0"><strong>Waiting for the client:</strong> <?= nl2br(e($e['info_note'])) ?> <span class="text-muted">(asked <?= time_ago($e['info_requested_at']) ?>)</span></div><?php endif; ?>
          <div class="card"><div class="card-header-lite"><?= e($s['form']) ?></div><div class="card-body"><?= eng_answers_html($e) ?: '<span class="text-muted small">Empty.</span>' ?></div></div>
        </div>
        <div class="vstack gap-3">
          <div class="card" id="authorize"><div class="card-header-lite">Activation conditions</div>
            <?php foreach ($gates as $k => [$label, $ok]): ?><div class="onb-check <?= $ok ? 'done' : '' ?>"><i class="<?= $ok ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line' ?>"></i><div class="grow small"><?= e($label) ?></div></div><?php endforeach; ?>
            <?php if (in_array($e['status'], ENG_PROPOSAL_STAGE, true)): ?>
              <div class="card-body border-top">
              <?php if ($canApprove && $gates['proposal'][1] && $gates['documents'][1] && $gates['payment'][1]): ?>
                <form method="post" action="<?= e(url('engagements', ['a' => 'authorize'])) ?>" class="vstack gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                  <?php if ($e['service'] === 'it'): ?><label class="form-label small mb-0" for="mgr">Project manager</label><select class="form-select form-select-sm" id="mgr" name="manager_id"><option value="">— Choose —</option><?php foreach (employee_options() as $k => $v): ?><option value="<?= (int) $k ?>"><?= e($v) ?></option><?php endforeach; ?></select><?php endif; ?>
                  <textarea class="form-control form-control-sm" name="note" rows="2" placeholder="Authorisation note (optional)"></textarea>
                  <button class="btn btn-success" data-confirm="Authorise <?= e($e['ref']) ?>? The <?= e(strtolower($s['output'])) ?> is created and goes live, and the company countersigns the agreements."><i class="ri-shield-check-line me-1"></i>Authorise &amp; activate</button></form>
              <?php elseif ($canApprove): ?><p class="small text-muted mb-0">You can authorise once the proposal and agreements are signed and the payment condition is met.</p>
              <?php else: ?><p class="small text-muted mb-0">Someone with <em>Authorise client activation</em> access gives the final approval.</p><?php endif; ?>
              </div>
            <?php elseif ($e['authorized_at']): ?><div class="card-body border-top small text-muted">Authorised by <?= e(login_name((int) $e['authorized_by'])) ?> on <?= fdate($e['authorized_at'], 'd M Y, h:i A') ?><?= $e['authorization_note'] ? ': ' . e($e['authorization_note']) : '' ?></div><?php endif; ?>
          </div>
          <div class="card"><div class="card-header-lite">Client</div><div class="card-body small"><dl class="detail-grid" style="grid-template-columns:1fr">
            <div><dt>Company</dt><dd><?= e($client['name']) ?><?= $client['industry'] ? ' · ' . e($client['industry']) : '' ?></dd></div>
            <div><dt>GSTIN · state</dt><dd><?= e($client['gst_no'] ?: '—') ?> · <?= e($client['state'] ?: '—') ?></dd></div>
            <div><dt>Contact</dt><dd><?= e($client['contact_person'] ?: '—') ?> · <?= e($client['email'] ?: '') ?> · <?= e($client['phone'] ?: '') ?></dd></div></dl>
            <div class="mt-2 vstack gap-1"><?php foreach ($logins as $l): ?><div class="d-flex justify-content-between gap-2"><span><?= e($l['username']) ?> <?= $l['designation'] ? '<span class="text-muted">· ' . e($l['designation']) . '</span>' : '' ?></span>
              <span><?= $l['is_signatory'] ? '<span class="pill pill-success">Signatory</span>' : '' ?> <span class="tag"><?= e(strtoupper($l['client_scope'] ?: 'HR+IT')) ?></span></span></div><?php endforeach; ?></div></div></div>
        </div>
      </div>

    <?php elseif ($tab === 'proposal'):
        $editable = $canManage && in_array($e['status'], ['Submitted', 'Info Requested', 'Proposal Sent'], true);
        $docs = eng_required_docs($e);
        $models = ENG_FEE_MODELS[$e['service']];
        $f = $e['form_data'];
        $defaultSop = $e['sop_text'] ?? ("### Deliverables\n- " . implode("\n- ", array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($f['features'] ?? 'Describe each deliverable'))))) . "\n\n### Out of scope\n- Hosting charges and third-party licences unless listed above\n\n### Assumptions\n- The client provides content, access and timely approvals"); ?>
      <div class="grid grid-3">
        <form method="post" action="<?= e(url('engagements', ['a' => 'commercials'])) ?>" class="span-2 card form-block"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
          <div class="form-block-head"><span class="fb-icon"><i class="ri-money-rupee-circle-line"></i></span><div><h6>Commercials<?= $e['service'] === 'it' ? ' & scope (SOP)' : '' ?></h6><small>Amounts exclude GST (<?= rtrim(rtrim(number_format(eng_gst_rate(), 2), '0'), '.') ?>%).</small></div></div>
          <fieldset class="form-block-body" <?= $editable ? '' : 'disabled' ?>><div class="row g-3">
            <?= form_field('title', ['label' => 'Title on documents', 'col' => 'col-12'], $e['title']) ?>
            <?= form_field('fee_model', ['label' => 'Fee model', 'type' => 'select', 'options' => $models, 'required' => true, 'col' => 'col-md-6'], $e['fee_model'] ?: ($e['service'] === 'hr' ? 'percent' : 'fixed')) ?>
            <?= form_field('fee_value', ['label' => $e['service'] === 'hr' ? 'Fee (% of CTC, or ₹ for fixed)' : 'Fee / rate (₹)', 'type' => 'number', 'step' => '0.01', 'min' => 0, 'required' => true, 'col' => 'col-md-6'], $e['fee_value'] ?? ($e['service'] === 'hr' ? setting('eng_hr_fee_percent', '8.33') : '')) ?>
            <?= form_field('estimate_amount', ['label' => 'Estimated value (₹)', 'type' => 'number', 'step' => '1', 'min' => 0, 'col' => 'col-md-4', 'help' => $e['service'] === 'hr' ? 'e.g. fee × openings × average CTC' : 'Total project value'], $e['estimate_amount']) ?>
            <div class="col-md-4"><label class="form-label" for="f_advance_amount">Advance before activation (₹)</label><div class="input-group"><input class="form-control" type="number" min="0" step="1" id="f_advance_amount" name="advance_amount" value="<?= e($e['advance_amount']) ?>">
              <button type="button" class="btn btn-light border" data-advance-pct="<?= e(setting('eng_advance_percent', 30)) ?>"><?= e(setting('eng_advance_percent', 30)) ?>%</button></div><div class="form-text">0 = no payment gate.</div></div>
            <?= form_field('valid_until', ['label' => 'Proposal valid until', 'type' => 'date', 'required' => true, 'col' => 'col-md-4', 'min' => date('Y-m-d')], $e['valid_until'] ?: date('Y-m-d', strtotime('+15 days'))) ?>
            <?php if ($e['service'] === 'hr'): ?><?= form_field('replacement_days', ['label' => 'Replacement guarantee (days)', 'type' => 'number', 'min' => 0, 'max' => 365, 'col' => 'col-md-4'], $e['replacement_days'] ?? setting('eng_replacement_days', 90)) ?><?php endif; ?>
            <?= form_field('payment_terms', ['label' => 'Payment terms', 'col' => $e['service'] === 'hr' ? 'col-md-8' : 'col-12', 'placeholder' => setting('invoice_due_days', 15) . ' days from the invoice date'], $e['payment_terms']) ?>
            <?php if ($e['service'] === 'it'): ?><?= form_field('sop_text', ['label' => 'Scope of work (SOP)', 'type' => 'textarea', 'rows' => 12, 'col' => 'col-12', 'help' => 'Deliverables, out of scope, assumptions, acceptance criteria. Changing it after signature creates a new SOP version for re-approval.'], $defaultSop) ?><?php endif; ?>
          </div></fieldset>
          <?php if ($editable): ?><div class="card-body border-top d-flex gap-2"><button class="btn btn-light border"><i class="ri-save-3-line me-1"></i>Save</button></div><?php endif; ?>
        </form>
        <div class="vstack gap-3">
          <div class="card"><div class="card-header-lite">Documents to sign</div>
            <?php foreach ($docs as $kind => $d): $sigs = $d ? eng_signatures((int) $d['id']) : []; ?>
              <div class="onb-doc"><div class="grow"><div class="fw-semibold small"><?= e(ENG_DOC_KINDS[$kind]['label']) ?> <?= $d ? '<span class="text-muted fw-normal">v' . (int) $d['version'] . '</span>' : '' ?></div>
                <div class="small text-muted"><?= $sigs ? e(implode(' · ', array_map(fn($x) => $x['signer_name'] . ' (' . ($x['party'] === 'company' ? 'us' : $x['method']) . ')', $sigs))) : ($d ? 'Issued ' . fdate($d['created_at']) : 'Issued with the proposal') ?></div></div>
                <?= $d ? badge($d['status']) : '' ?>
                <?php if ($d): ?><a class="btn btn-sm btn-icon" href="<?= e(url('engagements', ['a' => 'doc', 'id' => $d['id']])) ?>" title="View"><i class="ri-eye-line"></i></a><?php endif; ?></div>
              <?php if ($d && $d['status'] === 'Awaiting Signature' && $canManage): ?>
                <details class="px-3 pb-2 small"><summary class="text-muted">Signed outside the portal (Aadhaar eSign / DSC / wet ink)?</summary>
                  <form method="post" enctype="multipart/form-data" action="<?= e(url('engagements', ['a' => 'external_signed'])) ?>" class="vstack gap-1 mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="doc" value="<?= (int) $d['id'] ?>">
                    <input class="form-control form-control-sm" name="signer_name" placeholder="Signer's name" required><input class="form-control form-control-sm" name="signer_designation" placeholder="Designation">
                    <input class="form-control form-control-sm" name="provider" placeholder="Provider & reference (e.g. Leegality #12345)" required><input class="form-control form-control-sm" type="file" name="executed" accept=".pdf" required>
                    <button class="btn btn-sm btn-light border">Upload executed PDF</button></form></details>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($canManage && in_array($e['status'], ['Submitted', 'Info Requested', 'Proposal Sent'], true)): ?>
              <div class="card-body border-top"><?= $post('send_proposal', '<i class="ri-mail-send-line me-1"></i>' . ($e['status'] === 'Proposal Sent' ? 'Re-issue proposal & documents' : 'Send proposal & documents'), 'btn btn-primary w-100',
                  'Issue the proposal and agreements to the client\'s signatories? Documents already signed in the same version are not re-sent; changed ones get a new version.') ?>
                <div class="small text-muted mt-2">Save the commercials first. Signed versions are kept; changed documents are issued as new versions.</div></div>
            <?php endif; ?>
          </div>
          <div class="card card-body small text-muted"><i class="ri-shield-keyhole-line"></i> Signing needs an authorised signatory of the client, an e-mail OTP at the moment of signing and a drawn signature; the text's SHA-256, time, IP and device are recorded. For contracts that need Aadhaar eSign / DSC or e-stamping, sign with your provider and upload the executed copy.</div>
        </div>
      </div>
      <?php if (can_any('manage_engagements', 'bd_documents')):
          $mdocs = all("SELECT * FROM hrm_client_documents WHERE kind = 'managed' AND is_current = 1 AND status <> 'Void' AND client_id = ? AND (engagement_id = ? OR engagement_id IS NULL) ORDER BY engagement_id IS NULL, updated_at DESC LIMIT 50", [$e['client_id'], $e['id']]); ?>
      <div class="card mt-3"><div class="card-header-lite d-flex align-items-center"><span><i class="ri-file-shield-2-line me-1 text-primary"></i>Agreements &amp; documents</span>
        <a class="ms-auto small" href="<?= e(url('documents', ['client_id' => $e['client_id']])) ?>">All documents of this client</a></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Title</th><th>Type</th><th>Service</th><th>Scope</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>
          <?php foreach ($mdocs as $md): ?><tr><td><a class="fw-semibold" href="<?= e(url('documents', ['a' => 'view', 'id' => $md['id']])) ?>"><?= e($md['title']) ?></a></td><td class="small"><?= e(doc_type_label($md)) ?></td><td class="small"><?= e(DOC_CATEGORIES[doc_category($md) ?? ''] ?? '—') ?></td>
            <td class="small"><?= $md['engagement_id'] ? 'This engagement' : 'Company-level' ?></td><td>v<?= (int) $md['version'] ?></td><td><?= doc_status_pill($md) ?></td><td class="text-end"><a class="btn btn-sm btn-icon" href="<?= e(url('documents', ['a' => 'view', 'id' => $md['id']])) ?>" title="Open"><i class="ri-arrow-right-line"></i></a></td></tr><?php endforeach; ?>
          <?php if (!$mdocs): ?><tr><td colspan="7" class="small text-muted">No managed agreements yet. Upload the client's Word documents (MSA, NDA, DPA, SLA, SOW…) here to edit, version and send them for signature.</td></tr><?php endif; ?>
        </tbody></table></div>
        <form class="card-body border-top row g-2 align-items-end" method="post" enctype="multipart/form-data" action="<?= e(url('documents', ['a' => 'create'])) ?>"><?= csrf_field() ?>
          <input type="hidden" name="party" value="client"><input type="hidden" name="client_id" value="<?= (int) $e['client_id'] ?>"><input type="hidden" name="engagement_id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="mode" value="upload">
          <div class="col-md-3"><label class="form-label small" for="md_type">Document type</label><select class="form-select form-select-sm" id="md_type" name="doc_type" required><option value="">Choose…</option><?php foreach (DOC_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label small" for="md_cat">Service category</label><select class="form-select form-select-sm" id="md_cat" name="service_category" required><?php foreach (DOC_CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= ($e['service'] === 'hr' ? 'recruitment' : 'it_dev') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label small" for="md_file">Word document (.docx / .doc)</label><input class="form-control form-control-sm" type="file" id="md_file" name="file" accept=".docx,.doc" required></div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="ri-upload-2-line me-1"></i>Upload</button></div>
          <div class="col-12 small text-muted">Or <a href="<?= e(url('documents', ['a' => 'new', 'engagement_id' => $e['id']])) ?>">start from a template / blank document</a>. Documents above are separate from the proposal pack and are never removed or overwritten once signed.</div>
        </form></div>
      <?php endif; ?>

    <?php elseif ($tab === 'billing'):
        $inv = eng_advance_invoice($e);
        $invoices = all('SELECT * FROM hrm_invoices WHERE engagement_id = ? ORDER BY issue_date DESC, id DESC', [$e['id']]);
        $ms = all('SELECT * FROM hrm_engagement_milestones WHERE engagement_id = ? ORDER BY sort, due_date, id', [$e['id']]);
        $billed = array_sum(array_map(fn($i) => $i['status'] === 'Cancelled' ? 0 : (float) $i['total'], $invoices));
        $paid = array_sum(array_column($invoices, 'paid')); ?>
      <div class="grid grid-3 mb-3">
        <?= stat_card('Advance', (float) $e['advance_amount'] > 0 ? money(eng_advance_with_tax($e), true) : 'Not required', 'ri-hand-coin-line', eng_payment_ok($e) ? 'success' : 'warning', $e['payment_waived'] ? 'Waived: ' . e($e['waiver_note']) : ($inv ? e($inv['invoice_no']) . ' · ' . e($inv['status']) : ((float) $e['advance_amount'] > 0 ? 'Invoiced when the proposal is signed' : ''))) ?>
        <?= stat_card('Billed', money($billed, true), 'ri-file-list-3-line', 'info', count($invoices) . ' invoice(s)') ?>
        <?= stat_card('Outstanding', money(max(0, $billed - $paid), true), 'ri-time-line', $billed - $paid > 0.009 ? 'danger' : 'success', money($paid, true) . ' received') ?>
      </div>
      <div class="grid grid-3">
        <div class="span-2 vstack gap-3">
          <div class="card"><div class="card-header-lite">Payment milestones</div>
            <?php foreach ($ms as $m): ?><div class="list-row"><div class="grow"><div class="title"><?= e($m['title']) ?></div><div class="sub"><?= $m['due_date'] ? 'Due ' . fdate($m['due_date']) : 'No date' ?></div></div><strong><?= money($m['amount']) ?></strong> <?= badge($m['status']) ?>
              <?php if ($canManage && $m['status'] === 'Planned'): ?><?= $post('milestone_invoice', 'Invoice', 'btn btn-sm btn-soft-success', 'Issue an invoice for this milestone?', '', ['milestone' => $m['id']]) ?><?= $post('milestone_delete', '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Remove this milestone?', '', ['milestone' => $m['id']]) ?><?php endif; ?></div><?php endforeach; ?>
            <?php if (!$ms): ?><div class="card-body small text-muted">No milestones. Add them for milestone-based billing; they also appear in the proposal.</div><?php endif; ?>
            <?php if ($canManage && !in_array($e['status'], ['Closed', 'Cancelled', 'Declined'], true)): ?>
              <form method="post" action="<?= e(url('engagements', ['a' => 'milestone_add'])) ?>" class="card-body border-top row g-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                <div class="col-md-5"><input class="form-control form-control-sm" name="title" placeholder="Milestone (e.g. UAT sign-off)" required></div><div class="col-md-3"><input class="form-control form-control-sm" type="date" name="due_date"></div>
                <div class="col-md-2"><input class="form-control form-control-sm" type="number" min="1" step="1" name="amount" placeholder="₹" required></div><div class="col-md-2"><button class="btn btn-sm btn-light border w-100">Add</button></div></form>
            <?php endif; ?>
          </div>
          <div class="card"><div class="card-header-lite">Invoices</div>
            <?php foreach ($invoices as $i): ?><a class="list-row text-body" href="<?= e(url('invoices', ['a' => 'view', 'id' => $i['id']])) ?>"><div class="grow"><div class="title"><?= e($i['invoice_no']) ?></div><div class="sub"><?= fdate($i['issue_date']) ?> · due <?= fdate($i['due_date']) ?> · <?= money($i['paid'], true) ?> received</div></div><strong><?= money($i['total'], true) ?></strong> <?= badge(invoice_overdue($i) ? 'Overdue' : $i['status']) ?></a><?php endforeach; ?>
            <?php if (!$invoices): ?><div class="card-body small text-muted">No invoices yet. Record payments on the invoice (Finance → Invoices).</div><?php endif; ?>
          </div>
        </div>
        <div class="vstack gap-3">
          <?php if ((float) $e['advance_amount'] > 0 && !$e['advance_invoice_id'] && !$e['payment_waived'] && $canManage && in_array($e['status'], ENG_PROPOSAL_STAGE, true)): ?>
            <div class="card card-body small"><?= $post('create_advance', 'Issue advance invoice now', 'btn btn-light border w-100', 'Issue the advance invoice before the proposal is signed?') ?></div><?php endif; ?>
          <?php if ($canApprove && (float) $e['advance_amount'] > 0 && !$e['payment_waived'] && in_array($e['status'], ENG_PROPOSAL_STAGE, true) && !eng_payment_ok($e)): ?>
            <div class="card card-body small"><h6 class="fw-bold">Waive the advance</h6><p class="text-muted">For trusted clients (e.g. credit terms agreed). Recorded with your name in the audit log.</p>
              <?= $post('waive', 'Waive advance payment', 'btn btn-outline-warning w-100', '', 'Why is the advance waived?') ?></div><?php endif; ?>
          <div class="card card-body small text-muted"><i class="ri-information-line"></i> Payment reminders go out automatically 3 days before the due date and when overdue. Bank details come from Settings → Client invoices.</div>
        </div>
      </div>

    <?php elseif ($tab === 'delivery'):
        $del = eng_delivery($e);
        $files = all("SELECT * FROM hrm_client_documents WHERE engagement_id = ? AND kind IN ('deliverable','file') ORDER BY id DESC", [$e['id']]); ?>
      <?php if (!in_array($e['status'], ['Active', 'Delivered', 'Closed'], true)): ?>
        <div class="card"><?php empty_state('ri-rocket-line', 'Not active yet', 'Execution starts after internal authorisation creates the ' . strtolower($s['output']) . '.'); ?></div>
      <?php else: ?>
      <div class="grid grid-3">
        <div class="span-2 vstack gap-3">
          <div class="card"><div class="card-header-lite"><?= $e['service'] === 'hr' ? 'Vacancy pipeline' : 'Project progress' ?>
            <?= $e['job_id'] ? '<a class="small fw-normal" href="' . e(url('candidates', ['a' => 'board', 'job_id' => $e['job_id']])) . '">Open pipeline →</a>' : ($e['project_id'] ? '<a class="small fw-normal" href="' . e(url('projects', ['a' => 'view', 'id' => $e['project_id']])) . '">Open project →</a>' : '') ?></div>
            <div class="card-body"><div class="progress mb-3" style="height:10px"><div class="progress-bar" style="width:<?= (int) $del['pct'] ?>%"></div></div><div class="eng-kpis">
              <?php if ($del['kind'] === 'hr' && isset($del['openings'])): foreach (['Openings' => $del['openings'], 'Profiles' => $del['profiles'], 'Shortlisted' => $del['shortlisted'], 'Interviews' => $del['interviews'], 'Offers' => $del['offered'], 'Joined' => $del['joined']] as $l => $n): ?><div><strong><?= (int) $n ?></strong><small><?= $l ?></small></div><?php endforeach;
              elseif (isset($del['tasks'])): foreach (['Progress' => $del['progress'] . '%', 'Tasks done' => $del['done'] . '/' . $del['tasks'], 'Deliverables' => $del['deliverables'], 'Accepted' => $del['accepted']] as $l => $n): ?><div><strong><?= e((string) $n) ?></strong><small><?= $l ?></small></div><?php endforeach; endif; ?>
            </div></div></div>
          <div class="card"><div class="card-header-lite">Deliverables &amp; shared files</div>
            <?php foreach ($files as $d): ?><div class="list-row"><div class="grow"><div class="title"><?= e($d['title']) ?> <span class="small text-muted">v<?= (int) $d['version'] ?></span></div><div class="sub"><?= fdate($d['created_at']) ?><?= $d['review_note'] ? ' · ' . e($d['review_note']) : '' ?></div></div>
              <?php if ($d['file']): ?><a class="btn btn-sm btn-icon" href="<?= e(file_url($d['file'])) ?>" target="_blank" rel="noopener"><i class="ri-download-2-line"></i></a><?php endif; ?><?= badge($d['status']) ?></div><?php endforeach; ?>
            <?php if (!$files): ?><div class="card-body small text-muted">Nothing shared yet.</div><?php endif; ?>
            <?php if ($canManage && in_array($e['status'], ['Active', 'Delivered'], true)): ?>
              <form method="post" enctype="multipart/form-data" action="<?= e(url('engagements', ['a' => 'share'])) ?>" class="card-body border-top row g-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                <div class="col-md-5"><input class="form-control form-control-sm" name="title" placeholder="Title (e.g. Sprint 2 build, Weekly report)" required></div>
                <div class="col-md-3"><select class="form-select form-select-sm" name="type"><option value="deliverable">Deliverable (needs acceptance)</option><option value="file">File (for information)</option></select></div>
                <div class="col-md-4"><input class="form-control form-control-sm" type="file" name="file" required></div>
                <div class="col-md-9"><input class="form-control form-control-sm" name="note" placeholder="Note to the client (optional)"></div><div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Share</button></div></form>
            <?php endif; ?>
          </div>
        </div>
        <div class="vstack gap-3">
          <?php if ($canManage && $e['status'] === 'Active'): ?><div class="card card-body small"><h6 class="fw-bold">Delivery</h6><p class="text-muted"><?= $e['service'] === 'hr' ? 'When all positions are filled (candidates joined).' : 'When the deliverables are accepted.' ?></p>
            <?= $post('delivered', '<i class="ri-check-double-line me-1"></i>Mark delivered', 'btn btn-success w-100', '', 'Delivery note for the client (optional)') ?></div><?php endif; ?>
          <?php if ($canManage && in_array($e['status'], ['Active', 'Delivered'], true)): ?><div class="card card-body small"><h6 class="fw-bold">Closure</h6>
            <form method="post" action="<?= e(url('engagements', ['a' => 'close'])) ?>" class="vstack gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
              <select class="form-select form-select-sm" name="closure_type"><option>Completed</option><option>Cancelled</option></select><textarea class="form-control form-control-sm" name="note" rows="2" placeholder="Closure note (sent to the client)"></textarea>
              <button class="btn btn-outline-secondary btn-sm" data-confirm="Close <?= e($e['ref']) ?>? The <?= $e['service'] === 'hr' ? 'vacancy is closed' : 'project is marked completed' ?>.">Close</button></form></div><?php endif; ?>
          <?php if ($e['client_rating']): ?><div class="card card-body small"><h6 class="fw-bold">Client feedback</h6><div class="text-warning"><?= str_repeat('★', (int) $e['client_rating']) ?></div><?= e($e['client_feedback'] ?: '') ?></div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    <?php elseif ($tab === 'discussion'):
        $msgs = all('SELECT m.*, l.username, l.usertype FROM hrm_engagement_messages m LEFT JOIN it_login l ON l.id = m.login_id WHERE m.engagement_id = ? ORDER BY m.id', [$e['id']]);
        $tickets = all('SELECT * FROM hrm_tickets WHERE engagement_id = ? ORDER BY id DESC', [$e['id']]); ?>
      <div class="grid grid-3">
        <div class="span-2 card"><div class="eng-thread">
          <?php foreach ($msgs as $m): $client = $m['usertype'] && normalize_role($m['usertype']) === 'client'; ?>
            <div class="eng-msg <?= $client ? 'staff' : 'mine' ?> <?= $m['internal'] ? 'internal' : '' ?> kind-<?= e($m['kind']) ?>">
              <div class="eng-msg-head"><strong><?= e($m['username'] ?? 'System') ?><?= $client ? ' · client' : '' ?></strong><?= $m['internal'] ? '<span class="pill pill-neutral">Internal</span>' : '' ?><?= $m['kind'] !== 'comment' ? '<span class="pill pill-info">' . e(ucfirst($m['kind'])) . '</span>' : '' ?><small><?= time_ago($m['created_at']) ?></small></div>
              <?php if ($m['kind'] === 'meeting'): ?><div class="eng-meeting"><i class="ri-calendar-event-line"></i> <?= fdate($m['meeting_at'], 'l, d M Y · h:i A') ?><?= $m['meeting_link'] ? ' · <a href="' . e($m['meeting_link']) . '" target="_blank" rel="noopener">Link</a>' : '' ?></div><?php endif; ?>
              <div><?= nl2br(e($m['body'])) ?></div><?php if ($m['attachment']): ?><a class="small" href="<?= e(file_url($m['attachment'])) ?>" target="_blank" rel="noopener"><i class="ri-attachment-2"></i> Attachment</a><?php endif; ?>
              <?php if ($m['ticket_id']): ?><a class="small d-block" href="<?= e(url('support', ['a' => 'view', 'id' => $m['ticket_id']])) ?>"><i class="ri-coupon-3-line"></i> Ticket</a><?php endif; ?></div>
          <?php endforeach; ?>
          <?php if (!$msgs): ?><p class="small text-muted mb-0">No messages yet.</p><?php endif; ?></div>
          <?php if ($canManage): ?>
          <form method="post" enctype="multipart/form-data" action="<?= e(url('engagements', ['a' => 'message'])) ?>" class="card-body border-top vstack gap-2" data-msg-form><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
            <div class="btn-group btn-group-sm" role="group"><?php foreach (['comment' => 'Message', 'report' => 'Status report', 'meeting' => 'Meeting', 'internal' => 'Internal note'] as $k => $l): ?>
              <input type="radio" class="btn-check" name="kind" id="k_<?= $k ?>" value="<?= $k ?>" <?= $k === 'comment' ? 'checked' : '' ?> onchange="document.getElementById('meetRow').hidden=this.value!=='meeting'"><label class="btn btn-light border" for="k_<?= $k ?>"><?= $l ?></label><?php endforeach; ?></div>
            <div class="row g-2" id="meetRow" hidden><div class="col-md-5"><input class="form-control form-control-sm" type="datetime-local" name="meeting_at"></div><div class="col-md-7"><input class="form-control form-control-sm" name="meeting_link" placeholder="https://meet… (optional)"></div></div>
            <textarea class="form-control" name="note" rows="3" required placeholder="Write to the client (internal notes stay with your team)…"></textarea>
            <div class="d-flex gap-2"><input class="form-control form-control-sm" style="max-width:260px" type="file" name="attachment"><button class="btn btn-primary btn-sm ms-auto">Post</button></div></form>
          <?php endif; ?></div>
        <div class="card align-self-start"><div class="card-header-lite">Linked support tickets</div>
          <?php foreach ($tickets as $t): ?><a class="list-row text-body" href="<?= e(url('support', ['a' => 'view', 'id' => $t['id']])) ?>"><div class="grow"><div class="title"><?= e($t['ticket_no']) ?></div><div class="sub"><?= e($t['subject']) ?></div></div><?= badge($t['status']) ?></a><?php endforeach; ?>
          <?php if (!$tickets): ?><div class="card-body small text-muted">Escalations raised by the client appear here.</div><?php endif; ?></div>
      </div>

    <?php else: $events = all('SELECT ev.*, l.username FROM hrm_client_events ev LEFT JOIN it_login l ON l.id = ev.user_id WHERE ev.engagement_id = ? OR (ev.client_id = ? AND ev.engagement_id IS NULL) ORDER BY ev.id DESC', [$e['id'], $e['client_id']]); ?>
      <div class="card"><div class="card-header-lite">Audit trail <span class="small text-muted fw-normal">who did what, when and from where</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>When</th><th>Event</th><th>Details</th><th>By</th><th>IP</th></tr></thead><tbody>
          <?php foreach ($events as $ev): ?><tr><td class="small text-nowrap"><?= fdate($ev['created_at'], 'd M Y, h:i A') ?></td><td class="fw-semibold small"><?= e(ENG_EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></td><td class="small"><?= e($ev['note']) ?></td>
            <td class="small"><?= e($ev['username'] ?? 'System') ?> <span class="text-muted">(<?= e($ev['actor']) ?>)</span></td><td class="small text-muted"><?= e($ev['ip']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif;
    page_end(['js/onboarding.js', 'js/engagements.js']);
    return;
}

/* Staff document view (same rendering as the client's Document Centre). */
if ($a === 'doc') {
    $d = one('SELECT d.*, e.ref FROM hrm_client_documents d LEFT JOIN hrm_engagements e ON e.id = d.engagement_id WHERE d.id = ?', [int_input('id')]) ?? not_found();
    page_start($d['title'], ['Client Requests' => url('engagements'), 'v' . $d['version']], '<button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>');
    echo '<div class="card payslip"><div class="card-body p-4 p-md-5">' . eng_doc_letter($d, eng_certificate($d)) . '</div></div>';
    page_end();
    return;
}

/* ═════════════════════════ List ═════════════════════════ */
$filter = (string) input('status');
$svc = isset(ENG_SERVICES[input('service')]) ? input('service') : '';
$where = "e.status <> 'Draft'";
$params = [];
$tabs = ['' => 'Open', 'action' => 'Needs action', 'Submitted' => 'New', 'Proposal Sent' => 'Awaiting client', 'Awaiting Authorization' => 'To authorise', 'Active' => 'Active', 'closed' => 'Closed'];
if ($filter === 'action') $where .= " AND e.status IN ('Submitted','Awaiting Authorization')";
elseif ($filter === 'closed') $where .= " AND e.status IN ('Closed','Declined','Cancelled')";
elseif (in_array($filter, ENG_STATUSES, true)) { $where .= ' AND e.status = ?'; $params[] = $filter; }
else $where .= ' AND e.status IN ' . sql_list(ENG_OPEN);
if ($svc) { $where .= ' AND e.service = ?'; $params[] = $svc; }
if (($q = trim((string) input('q'))) !== '') { $where .= ' AND (e.ref LIKE ? OR e.title LIKE ? OR c.name LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$rows = all("SELECT e.*, c.name client_name, l.username owner_name FROM hrm_engagements e JOIN hrm_clients c ON c.id = e.client_id LEFT JOIN it_login l ON l.id = e.owner_id WHERE $where
             ORDER BY FIELD(e.status,'Submitted','Awaiting Authorization','Info Requested','Proposal Sent','Awaiting Payment','Active','Delivered','Closed','Declined','Cancelled'), e.submitted_at DESC LIMIT 300", $params);
$cnt = array_column(all("SELECT status, COUNT(*) n FROM hrm_engagements WHERE status <> 'Draft' GROUP BY status"), 'n', 'status');
$sum = fn(array $s) => array_sum(array_intersect_key($cnt, array_flip($s)));
$prospects = (int) val("SELECT COUNT(*) FROM hrm_clients WHERE status = 'Prospect'");
page_start('Client Requests', ['Client Requests'], ($canManage ? '<a class="btn btn-light border" href="' . e(url('engagements', ['a' => 'templates'])) . '"><i class="ri-file-copy-2-line me-1"></i>Templates</a>' : '')
    . (can('manage_settings') ? '<a class="btn btn-light border" href="' . e(url('engagements', ['a' => 'retention'])) . '"><i class="ri-archive-line me-1"></i>Retention</a>' : '')); ?>
<div class="grid grid-4 mb-3">
  <?= stat_card('New requests', $sum(['Submitted']), 'ri-inbox-line', 'primary', $sum(['Info Requested']) . ' waiting for client info', url('engagements', ['status' => 'Submitted'])) ?>
  <?= stat_card('Awaiting client', $sum(['Proposal Sent', 'Awaiting Payment']), 'ri-quill-pen-line', 'warning', $sum(['Awaiting Payment']) . ' awaiting payment', url('engagements', ['status' => 'Proposal Sent'])) ?>
  <?= stat_card('To authorise', $sum(['Awaiting Authorization']), 'ri-shield-check-line', 'danger', 'Signed & paid', url('engagements', ['status' => 'Awaiting Authorization'])) ?>
  <?= stat_card('Active', $sum(['Active', 'Delivered']), 'ri-rocket-line', 'success', $prospects . ' new client registration(s)', url('engagements', ['status' => 'Active'])) ?>
</div>
<div class="card">
  <nav class="list-tabs"><?php foreach ($tabs as $k => $l): ?><a class="<?= $filter === (string) $k ? 'active' : '' ?>" href="<?= e(url('engagements', array_filter(['status' => $k, 'service' => $svc, 'q' => $q]))) ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
  <form class="toolbar" method="get" action="<?= e(url('engagements')) ?>"><?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Reference, title or client…"></div>
    <select class="form-select" name="service" onchange="this.form.submit()"><option value="">HR &amp; IT</option><?php foreach (ENG_SERVICES as $k => $s): ?><option value="<?= $k ?>" <?= $svc === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select>
    <span class="ms-auto small text-muted result-count"><?= count($rows) ?> request(s)</span></form>
  <?php if (!$rows): empty_state('ri-service-line', 'No requests here', 'Client requests appear here when a client submits an HR or IT request from the portal. Clients can register at ' . root_url('client-register') . '.'); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Request</th><th>Client</th><th>Service</th><th>Status</th><th>Owner</th><th>Submitted</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $r['form_data'] = json_decode((string) $r['form'], true) ?: []; ?>
      <tr class="row-link" data-href="<?= e(url('engagements', ['a' => 'view', 'id' => $r['id']])) ?>"><td><a class="fw-semibold" href="<?= e(url('engagements', ['a' => 'view', 'id' => $r['id']])) ?>"><?= e(eng_title($r)) ?></a><div class="small text-muted"><?= e($r['ref']) ?></div></td>
        <td><?= e($r['client_name']) ?></td><td><span class="tag"><i class="<?= ENG_SERVICES[$r['service']]['icon'] ?>"></i> <?= strtoupper($r['service']) ?></span></td><td><?= badge($r['status']) ?></td>
        <td class="small"><?= e($r['owner_name'] ?: '—') ?></td><td class="small text-muted text-nowrap"><?= time_ago($r['submitted_at']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php
page_end();
