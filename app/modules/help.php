<?php
/**
 * Help Center: the feature guide filtered to what this user can use, plus knowledge-base articles
 * for their audience. a=article shows one article (with "was this helpful?"), a=suggest is the
 * JSON endpoint used by the new-ticket form, a=vote (POST) records helpful / not helpful.
 */

$a = $_GET['a'] ?? 'index';
$aud = kb_audiences();
$in = implode(',', array_fill(0, count($aud), '?'));

if ($a === 'suggest') {
    header('Content-Type: application/json');
    echo json_encode(['results' => array_map(fn($h) => ['title' => $h['title'], 'url' => $h['url']], assistant_search((string) input('q', ''), 5))]);
    exit;
}

if ($a === 'vote' && is_post()) {
    $id = int_input('id');
    $key = 'kb_voted_' . $id;
    if (empty($_SESSION[$key]) && val("SELECT id FROM hrm_kb_articles WHERE id = ? AND published = 1 AND audience IN ($in)", [$id, ...$aud])) {
        q('UPDATE hrm_kb_articles SET ' . (input('helpful') === '1' ? 'helpful = helpful + 1' : 'not_helpful = not_helpful + 1') . ' WHERE id = ?', [$id]);
        $_SESSION[$key] = 1;
    }
    flash('success', 'Thanks for the feedback!');
    redirect(url('help', ['a' => 'article', 'id' => $id]));
}

if ($a === 'article') {
    $art = one("SELECT * FROM hrm_kb_articles WHERE id = ? AND (published = 1 OR ?) AND audience IN ($in)", [int_input('id'), is_agent() ? 1 : 0, ...$aud]) ?? not_found();
    if (empty($_SESSION['kb_seen_' . $art['id']])) {
        q('UPDATE hrm_kb_articles SET views = views + 1 WHERE id = ?', [$art['id']]);
        $_SESSION['kb_seen_' . $art['id']] = 1;
    }
    $related = all("SELECT id, title FROM hrm_kb_articles WHERE published = 1 AND audience IN ($in) AND category = ? AND id <> ? ORDER BY views DESC LIMIT 5", [...$aud, $art['category'], $art['id']]);
    page_start($art['title'], ['Help Center' => url('help'), $art['category'], $art['title']],
        is_agent() ? '<a class="btn btn-light border" href="' . url('kb', ['a' => 'edit', 'id' => $art['id']]) . '"><i class="ri-pencil-line me-1"></i>Edit</a>' : '');
    ?>
    <div class="grid grid-3">
      <article class="card span-2"><div class="card-body kb-body">
        <?php if (!$art['published']): ?><div class="alert alert-warning small">Draft: only the support team can see this article.</div><?php endif; ?>
        <div class="small text-muted mb-3"><span class="tag"><?= e($art['category']) ?></span> Updated <?= fdate($art['updated_at'] ?: $art['created_at']) ?> · <?= (int) $art['views'] ?> views</div>
        <div class="md" data-md><?= e($art['body']) ?></div>
        <hr>
        <?php if (empty($_SESSION['kb_voted_' . $art['id']])): ?>
          <div class="d-flex flex-wrap gap-2 align-items-center"><span class="fw-semibold me-2">Was this helpful?</span>
            <?= post_button(url('help', ['a' => 'vote']), '<i class="ri-thumb-up-line me-1"></i>Yes', 'btn btn-sm btn-light border', '', ['id' => $art['id'], 'helpful' => 1]) ?>
            <?= post_button(url('help', ['a' => 'vote']), '<i class="ri-thumb-down-line me-1"></i>No', 'btn btn-sm btn-light border', '', ['id' => $art['id'], 'helpful' => 0]) ?>
          </div>
        <?php else: ?><div class="small text-muted">Thanks for your feedback.</div><?php endif; ?>
      </div></article>
      <div class="vstack gap-3">
        <?php if ($related): ?><div class="card"><div class="card-header-lite">Related articles</div>
          <?php foreach ($related as $r): ?><a class="list-row text-body" href="<?= url('help', ['a' => 'article', 'id' => $r['id']]) ?>"><i class="ri-article-line text-primary"></i><div class="grow title"><?= e($r['title']) ?></div></a><?php endforeach; ?></div><?php endif; ?>
        <?php if (can('raise_tickets')): ?><div class="card card-body small"><div class="fw-semibold mb-1">Still stuck?</div><a class="btn btn-primary btn-sm" href="<?= url('support', ['a' => 'create', 'subject' => $art['title']]) ?>">Raise a ticket</a></div><?php endif; ?>
      </div>
    </div>
    <?php
    page_end(['js/md.js']);
    return;
}

/* ── Index ── */
$q = trim((string) input('q'));
$results = $q !== '' ? assistant_search($q, 12) : [];
$guide = assistant_visible_guide();
$articles = all("SELECT id, title, category, views FROM hrm_kb_articles WHERE published = 1 AND audience IN ($in) ORDER BY category, views DESC", $aud);
$byCat = [];
foreach ($articles as $r) $byCat[$r['category']][] = $r;
page_start('Help Center', ['Help Center'], can('raise_tickets') ? '<a class="btn btn-primary" href="' . url('support', ['a' => 'create']) . '"><i class="ri-customer-service-2-line me-1"></i>Contact support</a>' : '');
?>
<div class="help-hero mb-4">
  <h2>How can we help, <?= e(user()['employee']['first_name'] ?? explode(' ', user()['display_name'])[0]) ?>?</h2>
  <form method="get" action="<?= base_path() ?>/dashboard/" class="help-search"><input type="hidden" name="r" value="help">
    <i class="ri-search-line"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search guides and articles, e.g. &quot;apply leave&quot;" autofocus>
    <button class="btn btn-primary">Search</button></form>
  <?php if (assistant_enabled()): ?><p class="small mt-2 mb-0 opacity-75"><i class="ri-sparkling-2-fill"></i> Or ask the AI assistant: tap <strong>Ask AI</strong> at the bottom right.</p><?php endif; ?>
</div>

<?php if ($q !== ''): ?>
  <div class="card mb-4"><div class="card-header-lite"><?= count($results) ?> result(s) for “<?= e($q) ?>” <a class="small fw-normal" href="<?= url('help') ?>">Clear</a></div>
    <?php foreach ($results as $r): ?>
      <a class="list-row text-body align-items-start" href="<?= e($r['kind'] === 'kb' ? $r['url'] : url('help') . '#g-' . md5($r['title'])) ?>"><i class="<?= $r['kind'] === 'kb' ? 'ri-article-line' : 'ri-guide-line' ?> text-primary mt-1"></i>
        <div class="grow"><div class="title"><?= e($r['title']) ?></div><div class="sub"><?= e(mb_strimwidth(preg_replace('/[*#\[\]]|\(http[^)]*\)/', '', str_replace("\n", ' ', $r['body'])), 0, 170, '…')) ?></div></div></a>
    <?php endforeach; ?>
    <?php if (!$results): ?><div class="card-body small text-muted">Nothing found. <?= can('raise_tickets') ? '<a href="' . url('support', ['a' => 'create', 'subject' => $q]) . '">Ask the support team</a>.' : '' ?></div><?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid grid-3">
  <div class="span-2">
    <h6 class="section-title">Guides for the modules you use</h6>
    <div class="accordion guide-list" id="guide">
      <?php foreach ($guide as $i => $g): $gid = 'g-' . md5($g['title']); ?>
        <div class="accordion-item" id="<?= $gid ?>">
          <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c<?= $i ?>" aria-expanded="false"><?= e($g['title']) ?></button></h3>
          <div id="c<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#guide"><div class="accordion-body">
            <div class="md" data-md><?= e($g['body']) ?></div>
            <a class="btn btn-sm btn-light border mt-2" href="<?= e(url($g['link'][0], $g['link'][1])) ?>"><?= e($g['link'][2]) ?> <i class="ri-arrow-right-line"></i></a>
          </div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="vstack gap-3">
    <?php foreach ($byCat as $cat => $list): ?>
      <div class="card"><div class="card-header-lite"><?= e($cat) ?> <span class="count-pill"><?= count($list) ?></span></div>
        <?php foreach (array_slice($list, 0, 6) as $r): ?><a class="list-row text-body" href="<?= url('help', ['a' => 'article', 'id' => $r['id']]) ?>"><i class="ri-article-line text-primary"></i><div class="grow title"><?= e($r['title']) ?></div></a><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$byCat): ?><div class="card card-body small text-muted">No articles yet.<?= is_agent() ? ' <a href="' . url('kb', ['a' => 'create']) . '">Write the first one</a>.' : '' ?></div><?php endif; ?>
    <?php if (can('raise_tickets')): ?>
      <div class="card card-body"><div class="fw-semibold"><i class="ri-customer-service-2-line text-primary me-1"></i>Talk to support</div>
        <p class="small text-muted mb-2">Critical issues get a reply within <?= sla_hours('Critical') ?> hours.</p>
        <a class="btn btn-light border btn-sm" href="<?= url('support') ?>">My tickets</a></div>
    <?php endif; ?>
  </div>
</div>
<?php
page_end(['js/md.js']);
