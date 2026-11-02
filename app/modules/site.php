<?php
/**
 * Website (Super Admin / manage_website): manage every part of the public home page without code.
 *   index                 page structure: order and visibility of sections, what has unpublished changes, revisions
 *   edit (s)              one section's form (texts, lists, links, icons, images) → saved as a draft
 *   layout · publish · discard · reset · restore (POST)
 * Drafts are visible only through Preview (/?preview=1 for a signed-in Super Admin); Publish makes them live and keeps a
 * revision of each published section so any earlier version can be restored.
 */

authorize('manage_website');
$a = $_GET['a'] ?? 'index';
$defaults = site_defaults();
$schema = site_schema();
$labels = SITE_GLOBAL + SITE_BODY;

/** Current draft data of a section (draft → live → defaults). */
function site_section(string $sec): array
{
    $row = one('SELECT draft, live FROM hrm_site_content WHERE section = ?', [$sec]);
    $data = $row ? json_decode((string) ($row['draft'] ?? $row['live'] ?? ''), true) : null;
    return array_replace(site_defaults()[$sec], is_array($data) ? array_intersect_key($data, site_defaults()[$sec]) : []);
}

function site_save_draft(string $sec, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    q('INSERT INTO hrm_site_content (section, draft, updated_by, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE draft = VALUES(draft), updated_by = VALUES(updated_by), updated_at = NOW()', [$sec, $json, user()['id']]);
}

/** Cleans one submitted field value by its type (never trusts the browser). */
function site_clean($v, array $def, string $field)
{
    [$label, $type] = $def;
    $opt = $def[2] ?? [];
    $str = fn($x, $max = 2000) => mb_substr(trim(str_replace("\r", '', is_string($x) ? $x : '')), 0, $max);
    switch ($type) {
        case 'bool': return !empty($v);
        case 'icon': return preg_match('/^ri-[a-z0-9-]+$/', $str($v, 60)) ? $str($v, 60) : '';
        case 'color': return preg_match('/^#[0-9a-fA-F]{3,8}$/', $str($v, 9)) ? $str($v, 9) : '#07142a';
        case 'select': return array_key_exists((string) $v, $opt) ? (string) $v : (string) array_key_first($opt);
        case 'url': case 'text': return $str($v, $opt['maxlength'] ?? 500);
        case 'textarea': return $str($v, 4000);
        case 'list':
            $out = [];
            foreach (array_slice(is_array($v) ? $v : [], 0, 60) as $row) {
                if (!is_array($row)) continue;
                $item = [];
                foreach ($opt as $k => $sub) $item[$k] = site_clean($row[$k] ?? null, $sub, "$field.$k");
                if (array_filter($item, fn($x) => $x !== '' && $x !== false && $x !== null)) $out[] = $item; // empty rows are dropped
            }
            return $out;
    }
    return $str($v);
}

/* ── Save a section (draft) ── */
if ($a === 'save' && is_post()) {
    $sec = (string) input('s');
    if (!isset($schema[$sec])) not_found();
    $cur = site_section($sec);
    $data = [];
    $errors = [];
    foreach ($schema[$sec] as $k => $def) {
        if ($def[1] === 'image') {
            $data[$k] = $cur[$k] ?? '';
            if (!empty($_POST["remove_$k"])) $data[$k] = '';
            try {
                if ($up = store_upload("img_$k", 'site', 3)) $data[$k] = $up;
            } catch (RuntimeException $ex) { $errors[] = $def[0] . ': ' . $ex->getMessage(); }
            continue;
        }
        $data[$k] = site_clean($_POST['f'][$k] ?? null, $def, $k);
    }
    if ($sec === 'general' && $data['whatsapp'] !== '' && !preg_match('/^\d{8,15}$/', $data['whatsapp'])) $errors[] = 'WhatsApp number: digits only, with the country code (e.g. 919871864942).';
    if ($sec === 'general' && $data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid e-mail address.';
    if ($errors) {
        flash('danger', implode('<br>', array_map('e', $errors)));
        redirect(url('site', ['a' => 'edit', 's' => $sec]));
    }
    site_save_draft($sec, $data);
    log_activity('website_draft', 'website', 0, $labels[$sec] ?? $sec);
    if (input('then') === 'preview') redirect(root_url('?preview=1') . (isset(SITE_BODY[$sec]) && $sec !== 'hero' ? '#' . $sec : ''));
    flash('success', '<strong>' . e($labels[$sec] ?? $sec) . '</strong> saved as a draft. Preview it, then publish when you are happy.');
    redirect(url('site', ['a' => 'edit', 's' => $sec]));
}

/* ── Layout: order and visibility ── */
if ($a === 'layout' && is_post()) {
    $order = array_values(array_intersect(array_map('strval', (array) ($_POST['order'] ?? [])), array_keys(SITE_BODY)));
    foreach (array_keys(SITE_BODY) as $k) if (!in_array($k, $order, true)) $order[] = $k;
    $visible = array_map('strval', (array) ($_POST['visible'] ?? []));
    $hidden = array_values(array_diff(array_keys(SITE_BODY), $visible));
    site_save_draft('layout', ['order' => $order, 'hidden' => $hidden]);
    log_activity('website_draft', 'website', 0, 'Page layout');
    flash('success', 'Page layout saved as a draft.' . ($hidden ? ' Hidden: ' . e(implode(', ', array_map(fn($k) => SITE_BODY[$k], $hidden))) . '.' : ''));
    redirect(url('site'));
}

/* ── Publish / discard / reset / restore ── */
if (in_array($a, ['publish', 'discard', 'reset', 'restore'], true) && is_post()) {
    if ($a === 'publish') {
        $pending = site_pending();
        db()->beginTransaction();
        foreach ($pending as $sec) {
            $draft = (string) val('SELECT draft FROM hrm_site_content WHERE section = ?', [$sec]);
            q('UPDATE hrm_site_content SET live = draft, published_by = ?, published_at = NOW() WHERE section = ?', [user()['id'], $sec]);
            insert('hrm_site_revisions', ['section' => $sec, 'data' => $draft, 'note' => mb_substr(trim((string) input('note')), 0, 190) ?: null, 'published_by' => user()['id']]);
        }
        db()->commit();
        log_activity('website_published', 'website', 0, implode(', ', array_map(fn($s) => SITE_GLOBAL[$s] ?? SITE_BODY[$s] ?? ($s === 'layout' ? 'Layout' : $s), $pending)));
        flash('success', $pending ? 'Published ' . count($pending) . ' section(s). The website shows the new content now.' : 'Nothing to publish.');
    } elseif ($a === 'discard') {
        $sec = (string) input('s');
        $sec === '' ? q('UPDATE hrm_site_content SET draft = live') : q('UPDATE hrm_site_content SET draft = live WHERE section = ?', [$sec]);
        q('DELETE FROM hrm_site_content WHERE live IS NULL AND draft IS NULL');
        log_activity('website_discard', 'website', 0, $sec ?: 'all drafts');
        flash('success', 'Unpublished changes discarded.');
    } elseif ($a === 'reset') {
        $sec = (string) input('s');
        if (!isset($defaults[$sec])) not_found();
        site_save_draft($sec, $defaults[$sec]);
        flash('success', e($labels[$sec] ?? 'Layout') . ' reset to the original content (draft). Publish to apply it.');
        redirect(url('site', $sec === 'layout' ? [] : ['a' => 'edit', 's' => $sec]));
    } else {
        $rev = one('SELECT * FROM hrm_site_revisions WHERE id = ?', [int_input('id')]) ?? not_found();
        site_save_draft($rev['section'], json_decode((string) $rev['data'], true) ?: []);
        log_activity('website_restore', 'website', (int) $rev['id'], $rev['section']);
        flash('success', 'Version from ' . e(fdate($rev['published_at'], 'd M Y, h:i A')) . ' restored as a draft. Preview, then publish.');
    }
    redirect(url('site'));
}

/* ── Edit a section ── */
if ($a === 'edit') {
    $sec = (string) input('s');
    if (!isset($schema[$sec])) not_found();
    $data = site_section($sec);
    $pending = in_array($sec, site_pending(), true);
    $icons = array_values(array_unique(array_merge(['ri-check-line', 'ri-star-fill', 'ri-rocket-2-line', 'ri-heart-line', 'ri-trophy-line', 'ri-global-line', 'ri-award-line', 'ri-lightbulb-line',
        'ri-settings-3-line', 'ri-bar-chart-box-line', 'ri-shield-check-line', 'ri-time-line', 'ri-mail-line', 'ri-phone-line', 'ri-map-pin-2-line', 'ri-links-line'],
        array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveArrayIterator(site_defaults()))), fn($v) => is_string($v) && preg_match('/^ri-[a-z0-9-]+$/', $v)))));
    page_start('Website: ' . ($labels[$sec] ?? $sec), ['Website' => url('site'), $labels[$sec] ?? $sec],
        '<a class="btn btn-light border" href="' . e(root_url('?preview=1')) . '" target="_blank" rel="noopener"><i class="ri-eye-line me-1"></i>Preview</a>');
    $field = function (string $name, string $key, array $def, $val, string $id) {
        [$label, $type] = $def;
        $opt = $def[2] ?? [];
        $h = '';
        switch ($type) {
            case 'bool': return '<label class="form-check site-bool"><input type="hidden" name="' . $name . '" value=""><input class="form-check-input" type="checkbox" name="' . $name . '" value="1" ' . ($val ? 'checked' : '') . '> <span class="form-check-label">' . e($label) . '</span></label>';
            case 'textarea': return '<textarea class="form-control" id="' . $id . '" name="' . $name . '" rows="' . (str_contains($key, 'rows') || str_contains($key, 'points') ? 4 : 2) . '"' . (isset($opt['maxlength']) ? ' maxlength="' . (int) $opt['maxlength'] . '" data-counter="' . (int) ($opt['counter'] ?? $opt['maxlength']) . '"' : '') . '>' . e((string) $val) . '</textarea>';
            case 'select': $h = '<select class="form-select" id="' . $id . '" name="' . $name . '">'; foreach ($opt as $k => $l) $h .= '<option value="' . e((string) $k) . '"' . ((string) $val === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>'; return $h . '</select>';
            case 'icon': return '<div class="input-group site-icon"><span class="input-group-text"><i class="' . e(site_icon((string) $val)) . '" data-icon-preview></i></span><input class="form-control" id="' . $id . '" name="' . $name . '" value="' . e((string) $val) . '" list="siteIcons" placeholder="ri-…-line" pattern="ri-[a-z0-9-]+" data-icon-input></div>';
            case 'color': return '<input class="form-control form-control-color" type="color" id="' . $id . '" name="' . $name . '" value="' . e((string) $val) . '">';
            default: return '<input class="form-control" id="' . $id . '" name="' . $name . '" value="' . e((string) $val) . '"' . ($type === 'url' ? ' type="url" placeholder="https://…"' : '') . (isset($opt['maxlength']) ? ' maxlength="' . (int) $opt['maxlength'] . '" data-counter="' . (int) ($opt['counter'] ?? $opt['maxlength']) . '"' : '') . (isset($opt['pattern']) ? ' pattern="' . e($opt['pattern']) . '"' : '') . '>';
        }
    };
    ?>
    <?php if ($pending): ?><div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i class="ri-draft-line"></i> This section has unpublished changes.
      <?= post_button(url('site', ['a' => 'discard']), 'Discard them', 'btn btn-sm btn-link text-danger p-0', 'Discard the unpublished changes of this section?', ['s' => $sec]) ?></div><?php endif; ?>
    <form class="vstack gap-3" method="post" enctype="multipart/form-data" action="<?= e(url('site', ['a' => 'save'])) ?>" data-site-form style="max-width:1100px"><?= csrf_field() ?><input type="hidden" name="s" value="<?= e($sec) ?>">
      <datalist id="siteIcons"><?php foreach ($icons as $i): ?><option value="<?= e($i) ?>"><?php endforeach; ?></datalist>
      <?php $plain = array_filter($schema[$sec], fn($d) => $d[1] !== 'list'); if ($plain): ?>
      <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-edit-2-line"></i></span><div><h6><?= e($labels[$sec] ?? $sec) ?></h6><small>Texts are shown exactly as typed (no HTML).</small></div></div>
        <div class="form-block-body"><div class="row g-3">
          <?php foreach ($plain as $k => $def): $id = 'f_' . $k; ?>
            <?php if ($def[1] === 'image'): ?>
              <div class="col-md-6"><label class="form-label" for="img_<?= e($k) ?>"><?= e($def[0]) ?></label>
                <?php if (!empty($data[$k])): ?><div class="site-img mb-2"><img src="<?= e(file_url($data[$k])) ?>" alt=""><label class="small"><input type="checkbox" name="remove_<?= e($k) ?>" value="1"> Remove (use the default)</label></div><?php endif; ?>
                <input class="form-control" type="file" id="img_<?= e($k) ?>" name="img_<?= e($k) ?>" accept=".png,.jpg,.jpeg,.webp"><div class="form-text">PNG, JPG or WebP · max 3 MB</div></div>
            <?php elseif ($def[1] === 'bool'): ?>
              <div class="col-md-6 d-flex align-items-end"><?= $field("f[$k]", $k, $def, $data[$k], $id) ?></div>
            <?php else: ?>
              <div class="<?= $def[1] === 'textarea' ? 'col-12' : 'col-md-6' ?>"><label class="form-label" for="<?= $id ?>"><?= e($def[0]) ?></label><?= $field("f[$k]", $k, $def, $data[$k], $id) ?><?= isset(($def[2] ?? [])['counter']) ? '<div class="form-text" data-count-for="' . $id . '"></div>' : '' ?></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div></div></section>
      <?php endif; ?>
      <?php foreach (array_filter($schema[$sec], fn($d) => $d[1] === 'list') as $k => $def): $rows = $data[$k] ?: []; ?>
        <section class="card form-block site-list" data-list="<?= e($k) ?>">
          <div class="form-block-head"><span class="fb-icon"><i class="ri-list-check-2"></i></span><div><h6><?= e($def[0]) ?></h6><small><span data-list-count><?= count($rows) ?></span> item(s) · drag ⋮⋮ or use the arrows to reorder</small></div>
            <button type="button" class="btn btn-sm btn-light border ms-auto" data-list-add><i class="ri-add-line me-1"></i>Add</button></div>
          <div class="form-block-body vstack gap-2" data-list-rows>
            <?php foreach ($rows as $i => $row): ?>
              <div class="site-row" draggable="true"><span class="site-grip" title="Drag to reorder" aria-hidden="true">⋮⋮</span><div class="row g-2 grow">
                <?php foreach ($def[2] as $sk => $sd): ?><div class="<?= $sd[1] === 'textarea' ? 'col-12' : ($sd[1] === 'bool' ? 'col-md-2 d-flex align-items-end' : 'col-md') ?>"><?= $sd[1] === 'bool' ? '' : '<label class="form-label small mb-1">' . e($sd[0]) . '</label>' ?><?= $field("f[$k][$i][$sk]", $sk, $sd, $row[$sk] ?? '', "f_{$k}_{$i}_$sk") ?></div><?php endforeach; ?>
              </div><div class="site-row-actions"><button type="button" class="btn btn-sm btn-icon" data-row-up title="Move up" aria-label="Move up"><i class="ri-arrow-up-s-line"></i></button><button type="button" class="btn btn-sm btn-icon" data-row-down title="Move down" aria-label="Move down"><i class="ri-arrow-down-s-line"></i></button><button type="button" class="btn btn-sm btn-icon text-danger" data-row-del title="Remove" aria-label="Remove"><i class="ri-delete-bin-6-line"></i></button></div></div>
            <?php endforeach; ?>
          </div>
          <template data-list-template><div class="site-row" draggable="true"><span class="site-grip" aria-hidden="true">⋮⋮</span><div class="row g-2 grow">
            <?php foreach ($def[2] as $sk => $sd): ?><div class="<?= $sd[1] === 'textarea' ? 'col-12' : ($sd[1] === 'bool' ? 'col-md-2 d-flex align-items-end' : 'col-md') ?>"><?= $sd[1] === 'bool' ? '' : '<label class="form-label small mb-1">' . e($sd[0]) . '</label>' ?><?= $field("f[$k][__i__][$sk]", $sk, $sd, $sd[1] === 'select' ? array_key_first($sd[2]) : '', "f_{$k}___i___$sk") ?></div><?php endforeach; ?>
          </div><div class="site-row-actions"><button type="button" class="btn btn-sm btn-icon" data-row-up aria-label="Move up"><i class="ri-arrow-up-s-line"></i></button><button type="button" class="btn btn-sm btn-icon" data-row-down aria-label="Move down"><i class="ri-arrow-down-s-line"></i></button><button type="button" class="btn btn-sm btn-icon text-danger" data-row-del aria-label="Remove"><i class="ri-delete-bin-6-line"></i></button></div></div></template>
        </section>
      <?php endforeach; ?>
      <div class="form-actions sticky-actions">
        <?= post_button(url('site', ['a' => 'reset']), '<i class="ri-restart-line me-1"></i>Reset to original', 'btn btn-light border', 'Replace this section with the original content (as a draft)?', ['s' => $sec]) ?>
        <a class="btn btn-light border ms-auto" href="<?= e(url('site')) ?>">Back</a>
        <button class="btn btn-light border" name="then" value="preview"><i class="ri-eye-line me-1"></i>Save &amp; preview</button>
        <button class="btn btn-primary"><i class="ri-save-3-line me-1"></i>Save draft</button>
      </div>
    </form>
    <?php
    page_end(['js/site-editor.js']);
    return;
}

/* ── Overview ── */
$S = site_content(true);
$pending = site_pending();
$revs = all('SELECT r.id, r.section, r.note, r.published_at, l.username FROM hrm_site_revisions r LEFT JOIN it_login l ON l.id = r.published_by ORDER BY r.id DESC LIMIT 15');
$lastPub = val('SELECT MAX(published_at) FROM hrm_site_content');
$v7 = table_exists('hrm_visits') ? (int) val('SELECT COUNT(DISTINCT CONCAT(visitor, DATE(created_at))) FROM hrm_visits WHERE page = "landing" AND created_at >= ?', [date('Y-m-d', strtotime('-6 days'))]) : 0;
page_start('Website', ['Website'], '<a class="btn btn-light border" href="' . e(root_url('?preview=1')) . '" target="_blank" rel="noopener"><i class="ri-eye-line me-1"></i>Preview</a>'
    . '<a class="btn btn-light border" href="' . e(root_url('')) . '" target="_blank" rel="noopener"><i class="ri-external-link-line me-1"></i>Live site</a>'
    . ($pending ? str_replace('<button', '<button data-prompt="What changed? (optional note for the version history)"', post_button(url('site', ['a' => 'publish']), '<i class="ri-upload-cloud-2-line me-1"></i>Publish ' . count($pending) . ' change(s)', 'btn btn-primary')) : ''));
?>
<div class="kpi-strip mb-3">
  <?= kpi('Unpublished sections', count($pending), 'ri-draft-line', count($pending) ? '#f59e0b' : '#16a34a') ?>
  <?= kpi('Visible sections', count(SITE_BODY) - count($S['layout']['hidden']), 'ri-layout-masonry-line', '#6366f1') ?>
  <?= kpi('Home page visitors (7 days)', $v7, 'ri-user-voice-line', '#0ea5e9', url('insights')) ?>
  <?= kpi('Last published', $lastPub ? time_ago($lastPub) : 'Never', 'ri-time-line', '#64748b') ?>
</div>
<?php if ($pending): ?><div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i class="ri-draft-line"></i> Unpublished changes: <strong><?= e(implode(', ', array_map(fn($s) => $labels[$s] ?? 'Layout', $pending))) ?></strong>. Visitors still see the published page.
  <a class="ms-auto" href="<?= e(root_url('?preview=1')) ?>" target="_blank" rel="noopener">Preview</a> <?= post_button(url('site', ['a' => 'discard']), 'Discard all', 'btn btn-sm btn-link text-danger p-0', 'Discard every unpublished change?') ?></div><?php endif; ?>
<div class="grid grid-3">
  <form class="span-2 card" method="post" action="<?= e(url('site', ['a' => 'layout'])) ?>" data-layout-form><?= csrf_field() ?>
    <div class="card-header-lite"><span><i class="ri-layout-masonry-line me-1 text-primary"></i>Home page sections</span><span class="small text-muted fw-normal">Drag or use the arrows to reorder · switch off to hide</span></div>
    <div data-layout-rows>
      <?php foreach ($S['layout']['order'] as $k): $on = !in_array($k, $S['layout']['hidden'], true); ?>
        <div class="list-row site-layout-row<?= $on ? '' : ' is-off' ?>" draggable="true"><input type="hidden" name="order[]" value="<?= e($k) ?>"><span class="site-grip" aria-hidden="true">⋮⋮</span>
          <div class="grow"><div class="title"><?= e(SITE_BODY[$k]) ?> <?= in_array($k, $pending, true) ? '<span class="pill pill-warning">Draft</span>' : '' ?></div></div>
          <div class="form-check form-switch m-0" title="Show on the page"><input class="form-check-input" type="checkbox" role="switch" name="visible[]" value="<?= e($k) ?>" <?= $on ? 'checked' : '' ?> aria-label="Show <?= e(SITE_BODY[$k]) ?>"></div>
          <button type="button" class="btn btn-sm btn-icon" data-row-up aria-label="Move up"><i class="ri-arrow-up-s-line"></i></button><button type="button" class="btn btn-sm btn-icon" data-row-down aria-label="Move down"><i class="ri-arrow-down-s-line"></i></button>
          <a class="btn btn-sm btn-light border" href="<?= e(url('site', ['a' => 'edit', 's' => $k])) ?>">Edit</a></div>
      <?php endforeach; ?>
    </div>
    <div class="card-body border-top d-flex gap-2"><button class="btn btn-primary"><i class="ri-save-3-line me-1"></i>Save layout (draft)</button>
      <?= post_button(url('site', ['a' => 'reset']), 'Reset layout', 'btn btn-light border', 'Restore the original order and show every section (draft)?', ['s' => 'layout']) ?></div>
  </form>
  <div class="vstack gap-3">
    <div class="card"><div class="card-header-lite"><i class="ri-settings-4-line me-1 text-primary"></i>Site-wide</div>
      <?php foreach (SITE_GLOBAL as $k => $l): ?><a class="list-row text-body" href="<?= e(url('site', ['a' => 'edit', 's' => $k])) ?>"><div class="grow title"><?= e($l) ?> <?= in_array($k, $pending, true) ? '<span class="pill pill-warning">Draft</span>' : '' ?></div><i class="ri-arrow-right-s-line text-muted"></i></a><?php endforeach; ?></div>
    <div class="card"><div class="card-header-lite"><i class="ri-history-line me-1 text-primary"></i>Published versions</div>
      <?php foreach ($revs as $r): ?><div class="list-row"><div class="grow"><div class="title small"><?= e($labels[$r['section']] ?? ($r['section'] === 'layout' ? 'Page layout' : $r['section'])) ?></div><div class="sub"><?= fdate($r['published_at'], 'd M Y, h:i A') ?> · <?= e($r['username'] ?? '—') ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?></div></div>
        <?= post_button(url('site', ['a' => 'restore']), 'Restore', 'btn btn-sm btn-link p-0', 'Load this version as a draft? You can preview it before publishing.', ['id' => $r['id']]) ?></div><?php endforeach; ?>
      <?php if (!$revs): ?><div class="card-body small text-muted">Nothing published yet. The page shows the original content.</div><?php endif; ?></div>
  </div>
</div>
<?php
page_end(['js/site-editor.js']);
