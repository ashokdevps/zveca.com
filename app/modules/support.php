<?php
/**
 * Helpdesk for every kind of user.
 *   (default)  my tickets (requester view)          a=create / store      raise a ticket
 *   a=queue    support desk queue (manage_support)   a=view                conversation + properties
 *   a=reply    public reply or internal note         a=update              agent: status/priority/assignee/category
 *   a=take     assign to me                          a=rate / a=reopen     requester closes the loop
 */

$a = $_GET['a'] ?? 'index';
$uid = (int) user()['id'];

function find_ticket(int $id): array
{
    $t = one('SELECT * FROM hrm_tickets WHERE id = ?', [$id]);
    if (!$t || !ticket_visible($t)) not_found();
    return $t;
}

/* ───────────── Create ───────────── */
if ($a === 'store' && is_post()) {
    authorize_any('raise_tickets', 'manage_support');
    $subject = trim((string) input('subject'));
    $body = trim((string) ($_POST['body'] ?? ''));
    $category = in_array(input('category'), ticket_categories(), true) ? input('category') : 'General';
    $priority = in_array(input('priority'), TICKET_PRIORITIES, true) ? input('priority') : 'Medium';
    $errors = [];
    if (mb_strlen($subject) < 5) $errors[] = 'Give the ticket a short subject (at least 5 characters).';
    if (mb_strlen($body) < 10) $errors[] = 'Describe the problem so we can help (at least 10 characters).';
    // Simple flood guard: at most 10 new tickets per hour per user.
    if ((int) val('SELECT COUNT(*) FROM hrm_tickets WHERE requester_id = ? AND created_at >= ?', [$uid, date('Y-m-d H:i:s', time() - 3600)]) >= 10) $errors[] = 'You have raised many tickets in the last hour. Please add to an existing ticket instead.';
    $file = null;
    try {
        $file = store_upload('attachment', 'attachments', 8);
    } catch (RuntimeException $ex) {
        $errors[] = 'Attachment: ' . $ex->getMessage();
    }
    if ($errors) {
        $_SESSION['_old'] = $_POST;
        flash('danger', implode('<br>', array_map('e', $errors)));
        redirect(url('support', ['a' => 'create']));
    }
    $id = insert('hrm_tickets', [
        'ticket_no' => next_ticket_no(), 'subject' => mb_substr($subject, 0, 200), 'category' => $category, 'priority' => $priority,
        'status' => 'Open', 'channel' => input('from') === 'assistant' ? 'AI Assistant' : 'Portal', 'requester_id' => $uid,
        'sla_due_at' => date('Y-m-d H:i:s', time() + sla_hours($priority) * 3600), 'last_activity_at' => date('Y-m-d H:i:s'),
    ]);
    insert('hrm_ticket_messages', ['ticket_id' => $id, 'user_id' => $uid, 'body' => mb_substr($body, 0, 10000), 'attachment' => $file]);
    $t = one('SELECT * FROM hrm_tickets WHERE id = ?', [$id]);
    log_activity('created', 'ticket', $id, $t['ticket_no']);
    // Tell the support team.
    notify_perm('manage_support', 'ticket', "New {$priority} ticket {$t['ticket_no']}", login_name($uid) . ': ' . mb_strimwidth($subject, 0, 120, '…'), url('support', ['a' => 'view', 'id' => $id]), 'ri-customer-service-2-line', in_array($priority, ['High', 'Urgent'], true) ? 'warning' : 'info', "ticket:$id", false);
    $to = setting('support_email') ?: setting('company_email');
    if ($to) send_mail($to, "[{$t['ticket_no']}] New {$priority} ticket: {$subject}", login_name($uid) . ' (' . login_context($uid) . ") raised a ticket in \"$category\".\n\n$body");
    flash('success', 'Ticket <strong>' . e($t['ticket_no']) . '</strong> raised. We\'ll reply here and by e-mail.');
    redirect(url('support', ['a' => 'view', 'id' => $id]));
}

if ($a === 'create') {
    authorize_any('raise_tickets', 'manage_support');
    $cats = ticket_categories();
    $pre = ['subject' => old('subject', (string) input('subject', '')), 'category' => old('category', in_array(input('category'), $cats, true) ? input('category') : ''),
        'priority' => old('priority', 'Medium'), 'body' => old('body', '')];
    page_start('New ticket', ['My Tickets' => url('support'), 'New ticket']);
    ?>
    <div class="grid grid-3">
      <form class="card span-2" method="post" enctype="multipart/form-data" action="<?= e(url('support', ['a' => 'store'])) ?>">
        <?= csrf_field() ?><input type="hidden" name="from" value="<?= e(input('from', '')) ?>">
        <div class="card-body"><div class="row g-3">
          <?= form_field('subject', ['label' => 'Subject', 'required' => true, 'col' => 'col-12', 'placeholder' => 'e.g. Punch-in button not working on mobile', 'maxlength' => 200], $pre['subject']) ?>
          <?= form_field('category', ['label' => 'Category', 'type' => 'select', 'options' => $cats, 'required' => true, 'col' => 'col-md-6'], $pre['category']) ?>
          <?= form_field('priority', ['label' => 'Priority', 'type' => 'select', 'options' => array_combine(TICKET_PRIORITIES, array_map(fn($p) => $p . ' · reply within ' . sla_hours($p) . 'h', TICKET_PRIORITIES)), 'required' => true, 'col' => 'col-md-6',
              'help' => 'Critical = you can\'t work at all / many people affected.'], $pre['priority']) ?>
          <?= form_field('body', ['label' => 'Describe the problem', 'type' => 'textarea', 'rows' => 7, 'required' => true, 'col' => 'col-12', 'placeholder' => "What were you trying to do?\nWhat happened instead? Any error message?\nSince when?"], $pre['body']) ?>
          <div class="col-12"><label class="form-label" for="f_att">Attachment <small class="text-muted">(optional: screenshot, PDF, Excel; max 8 MB)</small></label>
            <input class="form-control" type="file" id="f_att" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt"></div>
        </div></div>
        <div class="form-actions px-3 pb-3"><a class="btn btn-light border ms-auto" href="<?= url('support') ?>">Cancel</a><button class="btn btn-primary"><i class="ri-send-plane-2-line me-1"></i>Submit ticket</button></div>
      </form>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite"><span><i class="ri-lightbulb-line text-warning me-1"></i>Suggested answers</span></div>
          <div class="card-body small" id="kbSuggest" data-url="<?= e(url('help', ['a' => 'suggest'])) ?>"><span class="text-muted">Start typing a subject and we'll suggest articles that may solve it instantly.</span></div></div>
        <?php if (can('use_assistant') && assistant_enabled()): ?>
        <div class="card card-body small"><div class="fw-semibold mb-1"><i class="ri-sparkling-2-fill text-primary me-1"></i>Try the AI assistant</div>
          <span class="text-muted">Most "how do I…" questions are answered instantly. Tap <strong>Ask AI</strong> at the bottom right.</span></div>
        <?php endif; ?>
      </div>
    </div>
    <script>
    (function () {
      var box = document.getElementById('kbSuggest'), s = document.getElementById('f_subject'), timer;
      function run() {
        var q = s.value.trim(); if (q.length < 4) return;
        fetch(box.dataset.url + '&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
          box.innerHTML = '';
          if (!d.results.length) { box.innerHTML = '<span class="text-muted">No matching articles. Submit the ticket and we\'ll help.</span>'; return; }
          d.results.forEach(function (x) { var a = document.createElement('a'); a.href = x.url; a.target = '_blank'; a.className = 'kb-hit'; a.innerHTML = '<i class="ri-article-line"></i><span></span>'; a.querySelector('span').textContent = x.title; box.appendChild(a); });
        }).catch(function () {});
      }
      s && s.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 400); });
      run();
    })();
    </script>
    <?php
    page_end();
    return;
}

/* ───────────── Replies & changes ───────────── */
if ($a === 'reply' && is_post()) {
    $t = find_ticket(int_input('id'));
    $agent = is_agent();
    $body = trim((string) ($_POST['body'] ?? ''));
    $internal = $agent && !empty($_POST['internal']);
    $file = null;
    try {
        $file = store_upload('attachment', 'attachments', 8);
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
        redirect(url('support', ['a' => 'view', 'id' => $t['id']]));
    }
    if ($body === '' && !$file) {
        flash('warning', 'Write a message first.');
        redirect(url('support', ['a' => 'view', 'id' => $t['id']]));
    }
    ticket_message((int) $t['id'], mb_substr($body ?: '(attachment)', 0, 10000), $internal, false, $file);
    $mine = (int) $t['requester_id'] === $uid;
    if ($agent && !$mine && !$internal) {
        if (!$t['first_response_at']) q('UPDATE hrm_tickets SET first_response_at = NOW() WHERE id = ?', [$t['id']]);
        if (!$t['assignee_id']) q('UPDATE hrm_tickets SET assignee_id = ? WHERE id = ?', [$uid, $t['id']]);
        $next = in_array(input('then'), TICKET_STATUSES, true) ? input('then') : 'Waiting on Customer';
        ticket_set_status(one('SELECT * FROM hrm_tickets WHERE id = ?', [$t['id']]), $next);
        ticket_notify($t, (string) $t['requester_id'], login_name($uid) . " replied:\n\n" . $body);
    } elseif ($mine && !$internal) {
        // The requester answered: back to the support team.
        if (in_array($t['status'], ['Waiting on Customer', 'Resolved'], true)) ticket_set_status($t, 'Open');
        if ($t['assignee_id']) ticket_notify($t, (string) $t['assignee_id'], login_name($uid) . " replied:\n\n" . $body);
    }
    flash('success', $internal ? 'Internal note added.' : 'Reply sent.');
    redirect(url('support', ['a' => 'view', 'id' => $t['id']]) . '#latest');
}

if ($a === 'update' && is_post()) {
    authorize('manage_support');
    $t = find_ticket(int_input('id'));
    $changes = [];
    $prio = input('priority');
    if (in_array($prio, TICKET_PRIORITIES, true) && $prio !== $t['priority']) {
        // A new priority re-targets the SLA from the time the ticket was raised.
        update('hrm_tickets', ['priority' => $prio, 'sla_due_at' => date('Y-m-d H:i:s', strtotime($t['created_at']) + sla_hours($prio) * 3600)], (int) $t['id']);
        $changes[] = "priority to $prio";
    }
    $cat = input('category');
    if (in_array($cat, ticket_categories('agent'), true) && $cat !== $t['category']) {
        update('hrm_tickets', ['category' => $cat], (int) $t['id']);
        $changes[] = "category to $cat";
    }
    $assignee = int_input('assignee_id') ?: null;
    if ($assignee !== ($t['assignee_id'] ? (int) $t['assignee_id'] : null) && ($assignee === null || isset(agent_options()[$assignee]))) {
        update('hrm_tickets', ['assignee_id' => $assignee], (int) $t['id']);
        ticket_message((int) $t['id'], $assignee ? 'assigned the ticket to ' . login_name($assignee) : 'unassigned the ticket', true, true);
        if ($assignee) ticket_notify($t, (string) $assignee, login_name($uid) . ' assigned this ticket to you.');
    }
    if ($changes) ticket_message((int) $t['id'], 'changed the ' . implode(' and ', $changes), false, true);
    $status = input('status');
    if (in_array($status, TICKET_STATUSES, true) && $status !== $t['status']) {
        ticket_set_status(one('SELECT * FROM hrm_tickets WHERE id = ?', [$t['id']]), $status);
        if ($status === 'Resolved') ticket_notify($t, (string) $t['requester_id'], 'Your ticket was marked as resolved. If the problem is fixed, please rate the help you received. If not, just reply to reopen it.');
    }
    flash('success', 'Ticket updated.');
    redirect(url('support', ['a' => 'view', 'id' => $t['id']]));
}

if ($a === 'take' && is_post()) {
    authorize('manage_support');
    $t = find_ticket(int_input('id'));
    update('hrm_tickets', ['assignee_id' => $uid], (int) $t['id']);
    ticket_message((int) $t['id'], 'took the ticket', true, true);
    if ($t['status'] === 'Open') ticket_set_status(one('SELECT * FROM hrm_tickets WHERE id = ?', [$t['id']]), 'In Progress');
    redirect(url('support', ['a' => 'view', 'id' => $t['id']]));
}

if ($a === 'rate' && is_post()) {
    $t = find_ticket(int_input('id'));
    $rating = int_input('rating');
    if ((int) $t['requester_id'] !== $uid || $t['status'] !== 'Resolved' || $rating < 1 || $rating > 5) {
        flash('danger', 'This ticket cannot be rated right now.');
    } else {
        update('hrm_tickets', ['rating' => $rating, 'rating_comment' => mb_substr(trim((string) input('comment')), 0, 255) ?: null], (int) $t['id']);
        ticket_set_status(one('SELECT * FROM hrm_tickets WHERE id = ?', [$t['id']]), 'Closed');
        flash('success', 'Thanks for your feedback!');
    }
    redirect(url('support', ['a' => 'view', 'id' => $t['id']]));
}

if ($a === 'reopen' && is_post()) {
    $t = find_ticket(int_input('id'));
    if ((int) $t['requester_id'] === $uid && $t['status'] === 'Resolved') {
        ticket_set_status($t, 'Open');
        flash('info', 'Ticket reopened. Add a reply telling us what is still wrong.');
    }
    redirect(url('support', ['a' => 'view', 'id' => $t['id']]) . '#reply');
}

/* ───────────── Conversation ───────────── */
if ($a === 'view') {
    $t = find_ticket(int_input('id'));
    $agent = is_agent();
    $mine = (int) $t['requester_id'] === $uid;
    $msgs = all('SELECT * FROM hrm_ticket_messages WHERE ticket_id = ?' . ($agent ? '' : ' AND internal = 0') . ' ORDER BY id', [$t['id']]);
    [$slaLabel, $slaTone] = sla_state($t);
    $crumbs = $agent && !$mine ? ['Support Desk' => url('support', ['a' => 'queue'])] : ['My Tickets' => url('support')];
    page_start($t['ticket_no'] . ' · ' . $t['subject'], $crumbs + [$t['ticket_no']],
        $agent && !$t['assignee_id'] && in_array($t['status'], TICKET_ACTIVE, true) ? post_button(url('support', ['a' => 'take']), '<i class="ri-hand-heart-line me-1"></i>Take ticket', 'btn btn-primary', '', ['id' => $t['id']]) : '');
    $closed = in_array($t['status'], ['Closed'], true);
    ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php if ($mine && $t['status'] === 'Resolved'): ?>
          <div class="card card-body resolve-box">
            <div class="d-flex flex-wrap gap-3 align-items-center">
              <i class="ri-checkbox-circle-fill text-success fs-2"></i>
              <div class="grow"><strong>Support marked this as resolved.</strong><div class="small text-muted">Is your problem fixed? Rate the help to close the ticket, or reopen it.</div></div>
              <?= post_button(url('support', ['a' => 'reopen']), 'Not fixed. Reopen', 'btn btn-light border', '', ['id' => $t['id']]) ?>
            </div>
            <form method="post" action="<?= e(url('support', ['a' => 'rate'])) ?>" class="d-flex flex-wrap gap-2 align-items-center mt-3">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
              <div class="star-rate" role="radiogroup" aria-label="Rating"><?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="r<?= $i ?>" name="rating" value="<?= $i ?>" required><label for="r<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><i class="ri-star-fill"></i></label><?php endfor; ?></div>
              <input class="form-control form-control-sm" style="max-width:320px" name="comment" maxlength="255" placeholder="Anything we could do better? (optional)">
              <button class="btn btn-success btn-sm">Rate &amp; close</button>
            </form>
          </div>
        <?php endif; ?>
        <div class="card">
          <div class="thread">
            <?php $last = count($msgs) - 1; foreach ($msgs as $i => $m):
                $who = login_name($m['user_id'] ? (int) $m['user_id'] : null);
                $isReq = (int) $m['user_id'] === (int) $t['requester_id']; ?>
              <?php if ($m['event']): ?>
                <div class="t-event" <?= $i === $last ? 'id="latest"' : '' ?>><i class="ri-git-commit-line"></i><strong><?= e($who) ?></strong> <?= e($m['body']) ?> · <span title="<?= e(fdate($m['created_at'], 'd M Y, h:i A')) ?>"><?= time_ago($m['created_at']) ?></span></div>
              <?php else: ?>
                <div class="t-msg <?= $m['internal'] ? 'internal' : ($isReq ? 'from-req' : 'from-agent') ?>" <?= $i === $last ? 'id="latest"' : '' ?>>
                  <?= avatar($who, null, 'sm') ?>
                  <div class="t-bubble">
                    <div class="t-meta"><strong><?= e($who) ?></strong><?= $isReq ? '' : ' <span class="tag">Support</span>' ?><?= $m['internal'] ? ' <span class="pill pill-warning"><i class="ri-lock-line"></i> Internal note</span>' : '' ?>
                      <span class="text-muted" title="<?= e(fdate($m['created_at'], 'd M Y, h:i A')) ?>"><?= time_ago($m['created_at']) ?></span></div>
                    <div class="t-body"><?= nl2br(e($m['body'])) ?></div>
                    <?php if ($m['attachment']): ?><a class="t-att" target="_blank" href="<?= e(file_url($m['attachment'])) ?>"><i class="ri-attachment-2"></i><?= e(basename($m['attachment'])) ?></a><?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if (!$closed || $agent): ?>
        <form class="card" id="reply" method="post" enctype="multipart/form-data" action="<?= e(url('support', ['a' => 'reply'])) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <div class="card-body">
            <?php if ($agent && !$mine): ?>
              <div class="reply-tabs mb-2" role="tablist">
                <label><input type="radio" name="internal" value="" checked> <i class="ri-reply-line"></i> Public reply</label>
                <label><input type="radio" name="internal" value="1"> <i class="ri-lock-line"></i> Internal note</label>
              </div>
            <?php endif; ?>
            <textarea class="form-control" name="body" rows="4" placeholder="<?= $agent && !$mine ? 'Write your reply… (internal notes are never shown to the requester)' : 'Add a reply or more details…' ?>"></textarea>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <input class="form-control form-control-sm" style="max-width:280px" type="file" name="attachment" aria-label="Attachment">
              <?php if ($agent && !$mine): ?>
                <label class="small text-muted ms-auto" for="then">After a public reply set status:</label>
                <select class="form-select form-select-sm" style="max-width:200px" name="then" id="then">
                  <?php foreach (['Waiting on Customer', 'In Progress', 'Resolved'] as $s): ?><option><?= $s ?></option><?php endforeach; ?>
                </select>
              <?php endif; ?>
              <button class="btn btn-primary btn-sm <?= $agent && !$mine ? '' : 'ms-auto' ?>"><i class="ri-send-plane-2-line me-1"></i>Send</button>
            </div>
          </div>
        </form>
        <?php else: ?>
          <div class="card card-body small text-muted text-center">This ticket is closed. <a href="<?= url('support', ['a' => 'create', 'subject' => 'Follow-up to ' . $t['ticket_no'] . ': ' . $t['subject']]) ?>">Raise a follow-up ticket</a> if you need more help.</div>
        <?php endif; ?>
      </div>

      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">Details <?= badge($t['status']) ?></div><div class="card-body">
          <dl class="detail-grid" style="grid-template-columns:1fr">
            <div><dt>Requester</dt><dd><strong><?= e(login_name((int) $t['requester_id'])) ?></strong><br><small class="text-muted"><?= e(login_context((int) $t['requester_id'])) ?></small></dd></div>
            <div><dt>SLA</dt><dd><span class="pill pill-<?= $slaTone ?>"><?= e($slaLabel) ?></span><?= $t['sla_due_at'] && in_array($t['status'], TICKET_ACTIVE, true) ? '<br><small class="text-muted">Due ' . fdate($t['sla_due_at'], 'd M, h:i A') . '</small>' : '' ?></dd></div>
            <?php if (!$agent): ?>
              <div><dt>Priority</dt><dd><?= badge($t['priority']) ?></dd></div>
              <div><dt>Category</dt><dd><?= e($t['category']) ?></dd></div>
              <div><dt>Handled by</dt><dd><?= $t['assignee_id'] ? e(login_name((int) $t['assignee_id'])) : '<span class="text-muted">Not assigned yet</span>' ?></dd></div>
            <?php endif; ?>
            <div><dt>Raised</dt><dd><?= fdate($t['created_at'], 'd M Y, h:i A') ?> <small class="text-muted">via <?= e($t['channel']) ?></small></dd></div>
            <?php if ($t['first_response_at']): ?><div><dt>First response</dt><dd><?= e(minutes_to_hm((int) round((strtotime($t['first_response_at']) - strtotime($t['created_at'])) / 60)) ?: '< 1m') ?></dd></div><?php endif; ?>
            <?php if ($t['rating']): ?><div><dt>Rating</dt><dd class="text-warning"><?= str_repeat('★', (int) $t['rating']) ?><span class="text-muted"><?= str_repeat('★', 5 - (int) $t['rating']) ?></span><?= $t['rating_comment'] ? '<div class="small text-body mt-1">“' . e($t['rating_comment']) . '”</div>' : '' ?></dd></div><?php endif; ?>
          </dl>
        </div></div>
        <?php if ($agent): ?>
        <form class="card" method="post" action="<?= e(url('support', ['a' => 'update'])) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <div class="card-header-lite">Manage</div>
          <div class="card-body vstack gap-2">
            <?= form_field('status', ['label' => 'Status', 'type' => 'select', 'options' => TICKET_STATUSES, 'col' => ''], $t['status']) ?>
            <?= form_field('priority', ['label' => 'Priority', 'type' => 'select', 'options' => TICKET_PRIORITIES, 'col' => ''], $t['priority']) ?>
            <?= form_field('category', ['label' => 'Category', 'type' => 'select', 'options' => ticket_categories('agent'), 'col' => ''], $t['category']) ?>
            <?= form_field('assignee_id', ['label' => 'Assignee', 'type' => 'select', 'options' => agent_options(), 'placeholder' => '— Unassigned —', 'col' => ''], $t['assignee_id']) ?>
            <button class="btn btn-primary btn-sm mt-1">Save changes</button>
          </div>
        </form>
        <?php
          $other = all('SELECT id, ticket_no, subject, status FROM hrm_tickets WHERE requester_id = ? AND id <> ? ORDER BY id DESC LIMIT 5', [$t['requester_id'], $t['id']]);
          if ($other): ?>
          <div class="card"><div class="card-header-lite">Other tickets from this person</div>
            <?php foreach ($other as $o): ?><a class="list-row text-body" href="<?= url('support', ['a' => 'view', 'id' => $o['id']]) ?>"><div class="grow"><div class="title"><?= e($o['ticket_no']) ?></div><div class="sub text-truncate"><?= e($o['subject']) ?></div></div><?= badge($o['status']) ?></a><?php endforeach; ?>
          </div>
        <?php endif; endif; ?>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Lists ───────────── */
function ticket_rows(array $rows, bool $agentView): void
{
    if (!$rows) {
        empty_state('ri-coupon-3-line', 'No tickets here', $agentView ? 'Nothing matches this view. Nice work!' : 'Need help? Raise a ticket and our support team will get back to you.',
            $agentView ? '' : '<a class="btn btn-primary" href="' . url('support', ['a' => 'create']) . '"><i class="ri-add-line me-1"></i>New ticket</a>');
        return;
    }
    echo '<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Ticket</th>' . ($agentView ? '<th>Requester</th>' : '') . '<th>Status</th><th>Priority</th><th>SLA</th>'
        . ($agentView ? '<th>Assignee</th>' : '') . '<th>Updated</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        [$sl, $st] = sla_state($r);
        $href = url('support', ['a' => 'view', 'id' => $r['id']]);
        echo '<tr class="row-link" data-href="' . e($href) . '"><td><a class="fw-semibold" href="' . e($href) . '">' . e($r['subject']) . '</a><div class="small text-muted">' . e($r['ticket_no']) . ' · ' . e($r['category']) . '</div></td>'
            . ($agentView ? '<td class="small"><strong>' . e(login_name((int) $r['requester_id'])) . '</strong><br><span class="text-muted">' . e(ROLES[normalize_role($r['usertype'] ?? 'employee')]) . '</span></td>' : '')
            . '<td>' . badge($r['status']) . '</td><td>' . badge($r['priority']) . '</td><td><span class="pill pill-' . $st . '">' . e($sl) . '</span></td>'
            . ($agentView ? '<td class="small">' . ($r['assignee_id'] ? e(login_name((int) $r['assignee_id'])) : '<span class="text-muted">Unassigned</span>') . '</td>' : '')
            . '<td class="small text-muted">' . time_ago($r['last_activity_at'] ?? $r['created_at']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

if ($a === 'queue') {
    authorize('manage_support');
    $views = [
        'unassigned' => ['Unassigned', "t.assignee_id IS NULL AND t.status IN ('Open','In Progress')", []],
        'mine'       => ['Mine', "t.assignee_id = ? AND t.status IN ('Open','In Progress','Waiting on Customer')", [$uid]],
        'open'       => ['All open', "t.status IN ('Open','In Progress')", []],
        'overdue'    => ['SLA breached', "t.status IN ('Open','In Progress') AND t.sla_due_at < NOW()", []],
        'waiting'    => ['Waiting on customer', "t.status = 'Waiting on Customer'", []],
        'resolved'   => ['Resolved / closed', "t.status IN ('Resolved','Closed')", []],
    ];
    $v = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : 'open';
    [$vl, $vw, $vp] = $views[$v];
    $q = trim((string) input('q'));
    $cat = in_array(input('category'), ticket_categories('agent'), true) ? input('category') : '';
    $w = $vw;
    $p = $vp;
    if ($q !== '') { $w .= ' AND (t.subject LIKE ? OR t.ticket_no LIKE ? OR l.username LIKE ? OR l.email LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
    if ($cat) { $w .= ' AND t.category = ?'; $p[] = $cat; }
    $rows = all("SELECT t.*, l.usertype FROM hrm_tickets t LEFT JOIN it_login l ON l.id = t.requester_id WHERE $w
                 ORDER BY FIELD(t.priority,'Critical','High','Medium','Low'), t.sla_due_at IS NULL, t.sla_due_at, t.id DESC LIMIT 200", $p);
    $counts = [];
    foreach ($views as $k => [, $cw, $cp]) $counts[$k] = (int) val("SELECT COUNT(*) FROM hrm_tickets t WHERE $cw", $cp);
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $kpi = one("SELECT COUNT(*) created, SUM(resolved_at IS NOT NULL) resolved, AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) frt,
                AVG(CASE WHEN resolved_at IS NOT NULL THEN resolved_at <= sla_due_at END) sla_ok, AVG(rating) csat, COUNT(rating) rated
                FROM hrm_tickets WHERE created_at >= ?", [$since]);
    page_start('Support Desk', ['Support Desk'], '<a class="btn btn-light border" href="' . url('kb') . '"><i class="ri-book-open-line me-1"></i>Knowledge base</a>'
        . '<a class="btn btn-primary" href="' . url('support', ['a' => 'create']) . '"><i class="ri-add-line me-1"></i>New ticket</a>');
    ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Open tickets', $counts['open'], 'ri-inbox-line', 'primary', $counts['unassigned'] . ' unassigned', url('support', ['a' => 'queue', 'view' => 'unassigned'])) ?>
      <?= stat_card('SLA breached', $counts['overdue'], 'ri-alarm-warning-line', $counts['overdue'] ? 'danger' : 'success', $kpi['sla_ok'] !== null ? round($kpi['sla_ok'] * 100) . '% resolved in SLA (30d)' : 'No resolutions yet', url('support', ['a' => 'queue', 'view' => 'overdue'])) ?>
      <?= stat_card('Avg first response', $kpi['frt'] !== null ? (minutes_to_hm((int) $kpi['frt']) ?: '< 1m') : '—', 'ri-timer-flash-line', 'info', (int) $kpi['created'] . ' tickets in 30 days') ?>
      <?= stat_card('Customer satisfaction', $kpi['csat'] !== null ? number_format((float) $kpi['csat'], 1) . ' / 5' : '—', 'ri-emotion-happy-line', 'warning', (int) $kpi['rated'] . ' rating(s) in 30 days') ?>
    </div>
    <div class="card list-card">
      <nav class="list-tabs"><?php foreach ($views as $k => [$label]): ?><a class="<?= $v === $k ? 'active' : '' ?>" href="<?= url('support', ['a' => 'queue', 'view' => $k]) ?>"><?= e($label) ?> <span><?= $counts[$k] ?></span></a><?php endforeach; ?></nav>
      <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="support"><input type="hidden" name="a" value="queue"><input type="hidden" name="view" value="<?= e($v) ?>">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search subject, ticket no. or requester"></div>
        <select class="form-select" name="category" onchange="this.form.submit()"><option value="">All categories</option><?php foreach (ticket_categories('agent') as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
        <span class="ms-auto small text-muted"><?= count($rows) ?> ticket(s)</span></form>
      <?php ticket_rows($rows, true); ?>
    </div>
    <?php
    page_end();
    return;
}

/* My tickets (default) */
authorize_any('raise_tickets', 'manage_support');
$filter = $_GET['status'] ?? 'active';
$w = 'requester_id = ?' . ($filter === 'active' ? " AND status IN ('Open','In Progress','Waiting on Customer','Resolved')" : ($filter === 'closed' ? " AND status = 'Closed'" : ''));
$rows = all("SELECT * FROM hrm_tickets WHERE $w ORDER BY FIELD(status,'Waiting on Customer','Resolved','Open','In Progress','Closed'), id DESC", [$uid]);
page_start('My Tickets', ['My Tickets'], '<a class="btn btn-light border" href="' . url('help') . '"><i class="ri-question-line me-1"></i>Help Center</a><a class="btn btn-primary" href="' . url('support', ['a' => 'create']) . '"><i class="ri-add-line me-1"></i>New ticket</a>');
?>
<div class="card list-card">
  <nav class="list-tabs">
    <?php foreach (['active' => 'Active', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?><a class="<?= $filter === $k ? 'active' : '' ?>" href="<?= url('support', ['status' => $k]) ?>"><?= $l ?></a><?php endforeach; ?>
  </nav>
  <?php ticket_rows($rows, false); ?>
</div>
<?php
page_end();
