<?php
/**
 * AI assistant endpoints (JSON, used by assets/js/assistant.js) and its admin page.
 *   a=ask (POST)       question → answer        a=history   last messages of the conversation
 *   a=feedback (POST)  thumbs up / down          a=reset (POST) start a new conversation
 *   a=admin            Super Admin: API key, model, on/off, usage and unanswered questions
 */

$a = $_GET['a'] ?? 'admin';
$uid = (int) user()['id'];

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (in_array($a, ['ask', 'history', 'feedback', 'reset'], true)) {
    if (!assistant_enabled()) json_out(['error' => 'The assistant is turned off.'], 403);
}

if ($a === 'ask' && is_post()) {
    $q = trim((string) ($_POST['q'] ?? ''));
    if ($q === '' || mb_strlen($q) > 1000) json_out(['error' => 'Ask a question of up to 1000 characters.'], 422);
    if (assistant_rate_limited($uid)) json_out(['error' => 'You\'re asking very quickly. Please wait a few minutes and try again.'], 429);
    @set_time_limit(90);
    json_out(assistant_answer($q));
}

if ($a === 'history') {
    $rows = array_reverse(all('SELECT id, role, content, source FROM hrm_ai_messages WHERE user_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 20',
        [$uid, date('Y-m-d H:i:s', strtotime('-6 hours'))]));
    json_out(['messages' => $rows, 'greeting' => assistant_greeting(), 'suggestions' => assistant_suggestions()]);
}

if ($a === 'feedback' && is_post()) {
    $v = input('value') === '1' ? 1 : -1;
    q("UPDATE hrm_ai_messages SET feedback = ? WHERE id = ? AND user_id = ? AND role = 'assistant'", [$v, int_input('id'), $uid]);
    json_out(['ok' => true]);
}

if ($a === 'reset' && is_post()) {
    // Old messages stay for analytics; history only looks at the last 6 hours, so push them out of the window.
    q("UPDATE hrm_ai_messages SET created_at = DATE_SUB(created_at, INTERVAL 7 HOUR) WHERE user_id = ? AND created_at >= ?", [$uid, date('Y-m-d H:i:s', strtotime('-6 hours'))]);
    json_out(['ok' => true, 'greeting' => assistant_greeting()]);
}

/** Quick-start chips shown in an empty chat, based on what the user can do. */
function assistant_suggestions(): array
{
    $tools = assistant_tools();
    $s = [];
    foreach ([['get_my_leave_balance', 'My leave balance'], ['get_my_attendance', 'Did I punch in today?'], ['get_pending_approvals', 'Pending approvals'],
                 ['get_hr_snapshot', "Today's HR summary"], ['get_my_applications', 'My application status'], ['get_open_jobs', 'Show open jobs'],
                 ['get_my_submissions', 'My submissions'], ['get_my_company_summary', 'Project progress'], ['get_my_payslips', 'My last payslip']] as [$t, $label]) {
        if (isset($tools[$t])) $s[] = $label;
    }
    if (can('self_leaves')) $s[] = 'How do I apply for leave?';
    if (can('vendor_submissions')) $s[] = 'How do I submit a candidate?';
    if (can('candidate_jobs')) $s[] = 'How do I apply for a job?';
    if (can('manage_recruitment')) $s[] = 'How do I post a job?';
    if (can('raise_tickets')) $s[] = 'How do I raise a ticket?';
    return array_slice($s, 0, 5);
}

/* ───────────── Admin ───────────── */
authorize('manage_settings');

if ($a === 'test' && is_post()) {
    try {
        [$text] = assistant_claude([['role' => 'user', 'content' => 'Reply with exactly: Connection OK']]);
        flash('success', 'Claude replied: ' . e(mb_strimwidth($text, 0, 120, '…')));
    } catch (Throwable $e) {
        flash('danger', 'Connection failed: ' . e(mb_strimwidth($e->getMessage(), 0, 300, '…')));
    }
    redirect(url('assistant', ['a' => 'admin']));
}

if ($a === 'admin' && is_post()) {
    set_setting('ai_enabled', input('ai_enabled') ? '1' : '0');
    set_setting('resume_ai_parse', input('resume_ai_parse') ? '1' : '0');
    $model = trim((string) input('ai_model'));
    if (preg_match('/^claude-[a-z0-9.-]+$/', $model)) set_setting('ai_model', $model);
    if (in_array(input('ai_effort'), ['low', 'medium', 'high'], true)) set_setting('ai_effort', input('ai_effort'));
    set_setting('ai_rate_limit', max(5, min(200, int_input('ai_rate_limit', 20))));
    $key = trim((string) ($_POST['ai_api_key'] ?? ''));
    if (!empty($_POST['remove_key'])) {
        set_setting('ai_api_key', '');
    } elseif ($key !== '') {
        if (!str_starts_with($key, 'sk-ant-')) {
            flash('danger', 'That does not look like an Anthropic API key (it should start with sk-ant-).');
            redirect(url('assistant', ['a' => 'admin']));
        }
        set_setting('ai_api_key', $key);
    }
    log_activity('updated', 'settings', null, 'AI assistant');
    flash('success', 'AI assistant settings saved.');
    redirect(url('assistant', ['a' => 'admin']));
}

$since = date('Y-m-d H:i:s', strtotime('-30 days'));
$st = one("SELECT SUM(role='user') questions, COUNT(DISTINCT user_id) users, SUM(role='assistant' AND source='claude') claude,
           SUM(role='assistant' AND source='offline') offline, SUM(role='assistant' AND source='unanswered') unanswered,
           SUM(feedback = 1) up, SUM(feedback = -1) down, SUM(COALESCE(input_tokens,0)) tin, SUM(COALESCE(output_tokens,0)) tout
           FROM hrm_ai_messages WHERE created_at >= ?", [$since]);
// Questions the assistant could not answer or that were rated down: the best input for new KB articles.
$gaps = all("SELECT q.content question, a.source, a.feedback, q.created_at, l.username FROM hrm_ai_messages a
             JOIN hrm_ai_messages q ON q.id = (SELECT MAX(id) FROM hrm_ai_messages x WHERE x.user_id = a.user_id AND x.id < a.id AND x.role = 'user')
             LEFT JOIN it_login l ON l.id = a.user_id
             WHERE a.role = 'assistant' AND (a.source = 'unanswered' OR a.feedback = -1) AND a.created_at >= ? ORDER BY a.id DESC LIMIT 25", [$since]);
$tools = array_count_values(array_filter(explode(',', implode(',', array_column(all("SELECT tools FROM hrm_ai_messages WHERE tools IS NOT NULL AND created_at >= ?", [$since]), 'tools')))));
arsort($tools);
$fromConfig = (bool) (config('anthropic_api_key') ?: getenv('ANTHROPIC_API_KEY'));
$key = assistant_api_key();
page_start('AI Assistant', ['Administration', 'AI Assistant']);
?>
<div class="grid grid-4 mb-3">
  <?= stat_card('Questions (30 days)', (int) $st['questions'], 'ri-question-answer-line', 'primary', (int) $st['users'] . ' people used it') ?>
  <?= stat_card('Answered by Claude', (int) $st['claude'], 'ri-sparkling-2-fill', 'info', (int) $st['offline'] . ' from the built-in guide') ?>
  <?= stat_card('Not answered', (int) $st['unanswered'], 'ri-error-warning-line', $st['unanswered'] ? 'warning' : 'success', 'Write KB articles for these') ?>
  <?= stat_card('Helpful rating', ($st['up'] + $st['down']) ? round($st['up'] / ($st['up'] + $st['down']) * 100) . '%' : '—', 'ri-thumb-up-line', 'success', (int) $st['up'] . ' 👍 · ' . (int) $st['down'] . ' 👎') ?>
</div>
<div class="grid grid-3">
  <form class="card span-2" method="post" action="<?= e(url('assistant', ['a' => 'admin'])) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-header-lite">Configuration</div>
    <div class="card-body"><div class="row g-3">
      <?= form_field('ai_enabled', ['label' => 'Show the assistant to users', 'type' => 'checkbox', 'col' => 'col-12', 'help' => 'Only users with the "AI assistant" module see it (Users & Roles).'], setting('ai_enabled', '1') === '1') ?>
      <div class="col-12">
        <label class="form-label" for="f_key">Anthropic API key</label>
        <?php if ($fromConfig): ?>
          <div class="form-control-plaintext"><span class="pill pill-success">Set in server configuration</span> <small class="text-muted">(app/config.php or ANTHROPIC_API_KEY). Remove it there to manage it here.</small></div>
        <?php else: ?>
          <input class="form-control" type="password" id="f_key" name="ai_api_key" placeholder="<?= $key ? 'Saved: sk-ant-…' . e(substr($key, -4)) . ' (leave blank to keep)' : 'sk-ant-…' ?>" autocomplete="new-password">
          <div class="form-text">Get a key at console.anthropic.com. Without a key the assistant still answers from the built-in guide and knowledge base, and can read each user's own data (offline mode).
            <?php if ($key): ?><label class="ms-2"><input type="checkbox" name="remove_key" value="1"> Remove saved key</label><?php endif; ?></div>
        <?php endif; ?>
      </div>
      <?= form_field('ai_model', ['label' => 'Model', 'col' => 'col-md-6', 'help' => 'Default: claude-opus-5. Any current Claude model ID works.'], setting('ai_model', 'claude-opus-5')) ?>
      <?= form_field('ai_effort', ['label' => 'Answer depth (effort)', 'type' => 'select', 'options' => ['low' => 'Low: fastest, cheapest (recommended for chat)', 'medium' => 'Medium', 'high' => 'High: most thorough'], 'col' => 'col-md-6'], setting('ai_effort', 'low')) ?>
      <?= form_field('resume_ai_parse', ['label' => 'Read resumes with AI on job application links', 'type' => 'checkbox', 'col' => 'col-12', 'help' => 'With an API key, the text of a resume uploaded on an application link is sent to Claude to fill in the form more accurately. Without it (or when turned off) the built-in reader is used; nothing leaves the server.'], setting('resume_ai_parse', '1') === '1') ?>
      <?= form_field('ai_rate_limit', ['label' => 'Questions per user per 10 minutes', 'type' => 'number', 'min' => 5, 'max' => 200, 'col' => 'col-md-6'], setting('ai_rate_limit', 20)) ?>
    </div></div>
    <div class="form-actions px-3 pb-3">
      <?php if ($key): ?><?= post_button(url('assistant', ['a' => 'test']), '<i class="ri-plug-line me-1"></i>Test connection', 'btn btn-light border') ?><?php endif; ?>
      <button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save settings</button>
    </div>
  </form>
  <div class="vstack gap-3">
    <div class="card card-body">
      <div class="d-flex align-items-center gap-2 mb-2"><span class="ai-avatar"><i class="ri-sparkling-2-fill"></i></span><strong>Status</strong></div>
      <?php if (setting('ai_enabled', '1') !== '1'): ?><span class="pill pill-neutral">Turned off</span>
      <?php elseif ($key): ?><span class="pill pill-success">AI mode: <?= e(setting('ai_model', 'claude-opus-5')) ?></span>
      <?php else: ?><span class="pill pill-warning">Offline mode (no API key)</span><?php endif; ?>
      <p class="small text-muted mt-2 mb-0">The assistant only knows the modules each user has access to. Personal data tools are read-only and scoped to the signed-in user. Nobody can read someone else's data through it.</p>
      <?php if ($st['tin'] || $st['tout']): ?><p class="small text-muted mt-2 mb-0">Tokens (30 days): <?= indian_number((float) $st['tin']) ?> in · <?= indian_number((float) $st['tout']) ?> out.</p><?php endif; ?>
    </div>
    <div class="card"><div class="card-header-lite">Most used data tools</div><div class="card-body small">
      <?php foreach (array_slice($tools, 0, 8, true) as $t => $n): ?><div class="d-flex justify-content-between"><span><?= e(str_replace('_', ' ', preg_replace('/^get_/', '', $t))) ?></span><strong><?= $n ?></strong></div><?php endforeach; ?>
      <?php if (!$tools): ?><span class="text-muted">No usage yet.</span><?php endif; ?>
    </div></div>
  </div>
</div>
<div class="card mt-3"><div class="card-header-lite">Questions to improve on <small class="text-muted">Unanswered or rated 👎 in the last 30 days</small><a class="small fw-normal" href="<?= url('kb', ['a' => 'create']) ?>">+ Write an article</a></div>
  <?php if (!$gaps): ?><div class="card-body small text-muted">Nothing yet. Every question got an answer people liked.</div><?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Question</th><th>Asked by</th><th>Why</th><th>When</th></tr></thead><tbody>
    <?php foreach ($gaps as $g): ?><tr><td><?= e(mb_strimwidth($g['question'], 0, 140, '…')) ?></td><td class="small"><?= e($g['username'] ?? '—') ?></td>
      <td><?= $g['source'] === 'unanswered' ? '<span class="pill pill-warning">No answer</span>' : '<span class="pill pill-danger">Rated 👎</span>' ?></td><td class="small text-muted"><?= time_ago($g['created_at']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php
page_end();
