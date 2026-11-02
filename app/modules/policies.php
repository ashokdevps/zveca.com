<?php
/**
 * HR Policies (People → HR Policies). Every employee reads published policies and acknowledges them;
 * HR (manage_policies) writes, publishes and tracks acknowledgements.
 *   a=index            library (cards, search, categories) + Manage tab for HR
 *   a=view             the policy, its attachment and the acknowledgement panel
 *   a=create/edit/save/delete   CRUD (HR); ?template=<key> starts from a template (policy_templates())
 *   a=ack (POST)       acknowledge the current version
 *   a=publish / a=archive (POST)   status changes (HR)
 *   a=report / a=report_export / a=remind (POST)   who has / hasn't acknowledged (HR)
 * Helpers: app/lib/policies.php.
 */

$manage = can('manage_policies');
$me = my_employee_id();
$myDept = $me ? val('SELECT department_id FROM hrm_employees WHERE id = ?', [$me]) : null;
$a = $_GET['a'] ?? 'index';
$catOptions = array_combine(array_keys(POLICY_CATEGORIES), array_keys(POLICY_CATEGORIES));

/** The policy if this user may read it (HR: any; others: published + their audience). */
function policy_for_user(int $id): array
{
    global $manage, $myDept;
    return one('SELECT p.*, d.name dept FROM hrm_policies p LEFT JOIN hrm_departments d ON d.id = p.department_id WHERE p.id = ?'
        . ($manage ? '' : " AND p.status = 'Published' AND (p.department_id IS NULL OR p.department_id <=> ?)"), $manage ? [$id] : [$id, $myDept]) ?? not_found();
}

/* ── Acknowledge (current version) ── */
if ($a === 'ack' && is_post()) {
    $p = policy_for_user(int_input('id'));
    if (!$me) {
        flash('warning', 'Your login is not linked to an employee record, so it cannot record an acknowledgement.');
    } elseif ($p['status'] !== 'Published' || !$p['requires_ack']) {
        flash('warning', 'This policy does not need an acknowledgement.');
    } elseif (!input('agree')) {
        flash('danger', 'Tick the box to confirm you have read the policy.');
    } else {
        q('INSERT IGNORE INTO hrm_policy_acks (policy_id, employee_id, version, acknowledged_at, ip) VALUES (?, ?, ?, NOW(), ?)', [$p['id'], $me, $p['version'], client_ip()]);
        log_activity('acknowledged', 'Policy', (int) $p['id'], $p['title'] . ' v' . $p['version']);
        $left = count(policies_pending($me));
        flash('success', 'Thank you. You acknowledged <strong>' . e($p['title']) . '</strong> (version ' . e($p['version']) . ').' . ($left ? " $left more polic" . ($left === 1 ? 'y needs' : 'ies need') . ' your acknowledgement.' : ''));
    }
    redirect(url('policies', ['a' => 'view', 'id' => $p['id']]));
}

/* ── Publish / archive (HR) ── */
if (in_array($a, ['publish', 'archive'], true) && is_post()) {
    authorize('manage_policies');
    $p = policy_for_user(int_input('id'));
    if ($a === 'publish' && trim((string) $p['content']) === '' && !$p['attachment']) {
        flash('danger', 'Add the policy text or attach a document before publishing.');
    } else {
        q('UPDATE hrm_policies SET status = ?, published_at = ' . ($a === 'publish' ? 'COALESCE(published_at, NOW())' : 'published_at') . ', updated_at = NOW() WHERE id = ?', [$a === 'publish' ? 'Published' : 'Archived', $p['id']]);
        log_activity($a === 'publish' ? 'published' : 'archived', 'Policy', (int) $p['id'], $p['title']);
        flash('success', e($p['title']) . ($a === 'publish' ? ' is now published to ' . ($p['dept'] ? 'the ' . e($p['dept']) . ' department.' : 'everyone.') : ' archived. Employees no longer see it.'));
    }
    redirect(url('policies', ['a' => 'view', 'id' => $p['id']]));
}

/* ── Acknowledgement report (HR) ── */
if (in_array($a, ['report', 'report_export', 'remind'], true)) {
    authorize('manage_policies');
    $p = policy_for_user(int_input('id'));
    $acks = array_column(all('SELECT employee_id, acknowledged_at, ip FROM hrm_policy_acks WHERE policy_id = ? AND version = ?', [$p['id'], $p['version']]), null, 'employee_id');
    $people = policy_audience($p);
    foreach ($people as &$person) $person['ack'] = $acks[$person['id']] ?? null;
    unset($person);
    $pending = array_values(array_filter($people, fn($x) => !$x['ack']));

    if ($a === 'remind' && is_post()) {
        $sent = 0;
        $link = site_origin() . url('policies', ['a' => 'view', 'id' => $p['id']]);
        foreach ($pending as $x) {
            if ($x['email'] && send_mail($x['email'], 'Action needed: please read "' . $p['title'] . '"', "Hi {$x['first_name']},\n\nPlease read and acknowledge the {$p['title']} (version {$p['version']}) in the HR portal:\n$link\n\nIt takes a couple of minutes.\n\n— HR, " . setting('company_name'))) $sent++;
        }
        log_activity('reminded', 'Policy', (int) $p['id'], "$sent e-mails");
        flash($sent ? 'success' : 'warning', $sent ? "Reminder e-mailed to $sent employee(s)." : 'No reminders sent: the pending employees have no e-mail address on file.');
        redirect(url('policies', ['a' => 'report', 'id' => $p['id']]));
    }
    if ($a === 'report_export') {
        send_csv('policy-acknowledgements-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($p['title'])) . '-v' . $p['version'] . '.csv',
            ['Employee code', 'Name', 'Department', 'E-mail', 'Status', 'Acknowledged at', 'IP'],
            array_map(fn($x) => [$x['emp_code'], emp_name($x), $x['dept'], $x['email'], $x['ack'] ? 'Acknowledged' : 'Pending', $x['ack']['acknowledged_at'] ?? '', $x['ack']['ip'] ?? ''], $people));
    }
    $show = in_array($_GET['show'] ?? '', ['done', 'pending'], true) ? $_GET['show'] : 'all';
    $rows = array_filter($people, fn($x) => $show === 'all' || ($show === 'done') === (bool) $x['ack']);
    $done = count($people) - count($pending);
    $pct = $people ? (int) round($done / count($people) * 100) : 0;
    page_start('Acknowledgements', ['HR Policies' => url('policies'), $p['title'] => url('policies', ['a' => 'view', 'id' => $p['id']]), 'Acknowledgements'],
        '<a class="btn btn-light border" href="' . e(url('policies', ['a' => 'report_export', 'id' => $p['id']])) . '"><i class="ri-download-2-line me-1"></i>Export CSV</a>'
        . ($pending && $p['status'] === 'Published' ? post_button(url('policies', ['a' => 'remind', 'id' => $p['id']]), '<i class="ri-mail-send-line me-1"></i>Remind ' . count($pending) . ' pending', 'btn btn-primary', 'E-mail a reminder to the ' . count($pending) . ' employee(s) who have not acknowledged yet?', ['id' => $p['id']]) : ''));
    ?>
    <div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-4">
      <div class="pol-ring" style="--p:<?= $pct ?>"><span><?= $pct ?>%</span></div>
      <div class="flex-grow-1">
        <h2 class="h5 mb-1"><?= e($p['title']) ?> <span class="text-muted fw-normal">v<?= e($p['version']) ?></span></h2>
        <div class="text-muted small mb-2"><?= $p['dept'] ? 'Applies to the ' . e($p['dept']) . ' department' : 'Applies to everyone' ?> · <?= badge($p['status']) ?><?= $p['requires_ack'] ? '' : ' · <span class="text-muted">For information only (no acknowledgement required)</span>' ?></div>
        <div class="d-flex gap-4 small"><span><strong class="text-success fs-5"><?= $done ?></strong> acknowledged</span><span><strong class="text-warning fs-5"><?= count($pending) ?></strong> pending</span><span><strong class="fs-5"><?= count($people) ?></strong> employees</span></div>
      </div>
    </div></div>
    <div class="card list-card">
      <nav class="list-tabs">
        <?php foreach (['all' => ['All', count($people)], 'pending' => ['Pending', count($pending)], 'done' => ['Acknowledged', $done]] as $k => [$label, $n]): ?>
          <a class="<?= $show === $k ? 'active' : '' ?>" href="<?= e(url('policies', ['a' => 'report', 'id' => $p['id'], 'show' => $k === 'all' ? null : $k])) ?>"><?= $label ?> <span><?= $n ?></span></a>
        <?php endforeach; ?>
      </nav>
      <?php if (!$rows): empty_state('ri-user-search-line', 'Nobody here', $show === 'pending' ? 'Everyone has acknowledged this version.' : 'No acknowledgements yet.'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Employee</th><th>Department</th><th>Status</th><th>Acknowledged</th><th>IP address</th></tr></thead><tbody>
        <?php foreach ($rows as $x): $n = emp_name($x); ?>
          <tr><td><a class="person-link" href="<?= e(url('employees', ['a' => 'view', 'id' => $x['id']])) ?>"><?= avatar($n, $x['photo'], 'xs') ?><?= e($n) ?></a> <span class="text-muted small"><?= e($x['emp_code']) ?></span></td>
            <td><?= e($x['dept'] ?: '—') ?></td>
            <td><?= $x['ack'] ? '<span class="pill pill-success"><i class="ri-check-line"></i> Acknowledged</span>' : '<span class="pill pill-warning">Pending</span>' ?></td>
            <td class="small"><?= $x['ack'] ? fdate($x['ack']['acknowledged_at'], 'd M Y, h:i A') : '—' ?></td>
            <td class="small text-muted"><?= e($x['ack']['ip'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Library (everyone) + Manage tab (HR) ── */
function policies_index(array $res): void
{
    global $manage, $me, $myDept;
    $tab = $manage && ($_GET['tab'] ?? '') === 'manage' ? 'manage' : 'library';
    $visible = all("SELECT p.*, d.name dept FROM hrm_policies p LEFT JOIN hrm_departments d ON d.id = p.department_id
                    WHERE p.status = 'Published' AND (p.department_id IS NULL OR p.department_id <=> ? OR ?) ORDER BY p.category, p.title", [$myDept, $manage ? 1 : 0]);
    $myAcks = $me ? array_column(all('SELECT policy_id, version, acknowledged_at FROM hrm_policy_acks WHERE employee_id = ?', [$me]), null, null) : [];
    $ackedVer = [];
    foreach ($myAcks as $r) $ackedVer[$r['policy_id'] . '|' . $r['version']] = $r['acknowledged_at'];
    $pending = $me ? policies_pending($me) : [];
    $cats = array_count_values(array_column($visible, 'category'));
    $newMenu = '';
    if ($manage) {
        $newMenu = '<div class="dropdown d-inline-block"><button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-add-line me-1"></i>New policy</button>'
            . '<div class="dropdown-menu dropdown-menu-end shadow"><a class="dropdown-item" href="' . e(url('policies', ['a' => 'create'])) . '"><i class="ri-file-add-line me-2"></i>Blank policy</a><div class="dropdown-divider"></div><h6 class="dropdown-header">Start from a template</h6>';
        foreach (policy_templates() as $k => $t) {
            $newMenu .= '<a class="dropdown-item" href="' . e(url('policies', ['a' => 'create', 'template' => $k])) . '"><i class="' . policy_meta($t['category'])[0] . ' me-2"></i>' . e($t['title']) . '</a>';
        }
        $newMenu .= '</div></div>';
    }
    page_start('HR Policies', ['HR Policies'], $newMenu);
    ?>
    <section class="pol-hero">
      <div>
        <span class="pol-hero-kicker"><i class="ri-shield-check-line"></i> <?= e(setting('company_name')) ?></span>
        <h2>Policies &amp; guidelines</h2>
        <p>Everything you need to know about how we work, in one place. Read the policies that apply to you and acknowledge the ones marked <em>Action required</em>.</p>
      </div>
      <div class="pol-hero-stats">
        <div><strong><?= count($visible) ?></strong><span>Published</span></div>
        <?php if ($me): ?><div class="<?= $pending ? 'warn' : 'ok' ?>"><strong><?= count($pending) ?></strong><span>Awaiting you</span></div><?php endif; ?>
        <?php if ($manage): $drafts = (int) val("SELECT COUNT(*) FROM hrm_policies WHERE status = 'Draft'"); ?><div><strong><?= $drafts ?></strong><span>Draft<?= $drafts === 1 ? '' : 's' ?></span></div><?php endif; ?>
      </div>
    </section>

    <?php if ($pending): ?>
      <div class="pol-pending">
        <div class="pol-pending-head"><i class="ri-error-warning-line"></i><strong><?= count($pending) ?> polic<?= count($pending) === 1 ? 'y needs' : 'ies need' ?> your acknowledgement</strong></div>
        <?php foreach ($pending as $p): [$icon, $col] = policy_meta($p['category']); ?>
          <a class="pol-pending-row" href="<?= e(url('policies', ['a' => 'view', 'id' => $p['id']])) ?>"><span class="pol-icon sm" style="--c:<?= $col ?>"><i class="<?= $icon ?>"></i></span><span class="grow"><strong><?= e($p['title']) ?></strong><small>Version <?= e($p['version']) ?><?= $p['effective_date'] ? ' · effective ' . fdate($p['effective_date']) : '' ?></small></span><span class="btn btn-sm btn-primary">Read &amp; acknowledge</span></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($manage): ?>
      <nav class="pol-tabs" aria-label="Policy views">
        <a class="<?= $tab === 'library' ? 'active' : '' ?>" href="<?= e(url('policies')) ?>"><i class="ri-book-open-line"></i> Library</a>
        <a class="<?= $tab === 'manage' ? 'active' : '' ?>" href="<?= e(url('policies', ['tab' => 'manage'])) ?>"><i class="ri-settings-4-line"></i> Manage &amp; compliance</a>
      </nav>
    <?php endif; ?>

    <?php if ($tab === 'library'): ?>
      <div class="pol-toolbar" data-pol-filters>
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" placeholder="Search policies…" data-pol-search aria-label="Search policies"></div>
        <div class="pol-chips" role="group" aria-label="Category">
          <button type="button" class="on" data-cat="">All <span><?= count($visible) ?></span></button>
          <?php foreach ($cats as $c => $n): ?><button type="button" data-cat="<?= e($c) ?>"><?= e($c) ?> <span><?= $n ?></span></button><?php endforeach; ?>
          <?php if ($me && $pending): ?><button type="button" data-cat="__pending" class="<?= isset($_GET['pending']) ? 'on' : '' ?>"><i class="ri-error-warning-line"></i> Needs my acknowledgement <span><?= count($pending) ?></span></button><?php endif; ?>
        </div>
      </div>
      <?php if (!$visible): ?>
        <div class="card"><?php empty_state('ri-shield-check-line', 'No policies published yet', $manage ? 'Create your first policy, or start from a ready-made template.' : 'HR has not published any policies yet.', $manage ? '<a class="btn btn-primary" href="' . e(url('policies', ['a' => 'create', 'template' => 'conduct'])) . '">Start with the Code of Conduct</a>' : ''); ?></div>
      <?php else: ?>
      <div class="pol-grid" data-pol-grid>
        <?php foreach ($visible as $p): [$icon, $col] = policy_meta($p['category']);
            $ackAt = $ackedVer[$p['id'] . '|' . $p['version']] ?? null;
            $needs = $me && $p['requires_ack'] && !$ackAt && (!$p['department_id'] || (int) $p['department_id'] === (int) $myDept); ?>
          <a class="pol-card" href="<?= e(url('policies', ['a' => 'view', 'id' => $p['id']])) ?>" style="--c:<?= $col ?>" data-cat="<?= e($p['category']) ?>" data-pending="<?= $needs ? 1 : 0 ?>"
             data-search="<?= e(strtolower($p['title'] . ' ' . $p['summary'] . ' ' . $p['category'] . ' ' . $p['dept'])) ?>">
            <div class="pol-card-top"><span class="pol-icon" style="--c:<?= $col ?>"><i class="<?= $icon ?>"></i></span><span class="pol-cat"><?= e($p['category']) ?></span></div>
            <h3><?= e($p['title']) ?></h3>
            <p><?= e($p['summary'] ?: mb_strimwidth(preg_replace('/[#*\-\s]+/', ' ', strip_tags((string) $p['content'])), 0, 140, '…')) ?></p>
            <div class="pol-card-meta">
              <span>v<?= e($p['version']) ?></span>
              <?php if ($p['effective_date']): ?><span>Effective <?= fdate($p['effective_date']) ?></span><?php endif; ?>
              <?php if ($p['dept']): ?><span class="pol-dept"><i class="ri-building-2-line"></i><?= e($p['dept']) ?></span><?php endif; ?>
              <?php if ($p['attachment']): ?><span title="Has an attached document"><i class="ri-attachment-2"></i></span><?php endif; ?>
            </div>
            <div class="pol-card-status">
              <?php if ($needs): ?><span class="pill pill-warning"><i class="ri-error-warning-line"></i> Action required</span>
              <?php elseif ($ackAt): ?><span class="pill pill-success"><i class="ri-check-line"></i> Acknowledged <?= fdate($ackAt) ?></span>
              <?php elseif (!$p['requires_ack']): ?><span class="pill pill-neutral">For information</span>
              <?php else: ?><span class="pill pill-neutral">Not for your department</span><?php endif; ?>
              <span class="pol-read">Read <i class="ri-arrow-right-line"></i></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="card mt-3" data-pol-none hidden><?php empty_state('ri-search-eye-line', 'No policies match', 'Try another word or category.'); ?></div>
      <?php endif; ?>
      <script>
      (function () {
        var box = document.querySelector('[data-pol-filters]'), grid = document.querySelector('[data-pol-grid]');
        if (!box || !grid) return;
        var q = box.querySelector('[data-pol-search]'), cat = '', none = document.querySelector('[data-pol-none]');
        function run() {
          var s = q.value.trim().toLowerCase(), shown = 0;
          grid.querySelectorAll('.pol-card').forEach(function (c) {
            var ok = (!s || c.getAttribute('data-search').indexOf(s) > -1) && (!cat || (cat === '__pending' ? c.getAttribute('data-pending') === '1' : c.getAttribute('data-cat') === cat));
            c.hidden = !ok; if (ok) shown++;
          });
          none.hidden = shown > 0;
        }
        box.addEventListener('click', function (e) {
          var b = e.target.closest('[data-cat]'); if (!b) return;
          box.querySelectorAll('[data-cat]').forEach(function (x) { x.classList.toggle('on', x === b); });
          cat = b.getAttribute('data-cat'); run();
        });
        q.addEventListener('input', run);
        var on = box.querySelector('[data-cat].on'); if (on) { cat = on.getAttribute('data-cat'); run(); }
      })();
      </script>

    <?php else: // Manage & compliance
        $all = all('SELECT p.*, d.name dept FROM hrm_policies p LEFT JOIN hrm_departments d ON d.id = p.department_id ORDER BY FIELD(p.status, "Published", "Draft", "Archived"), p.updated_at DESC, p.id DESC'); ?>
      <div class="card list-card">
        <?php if (!$all): empty_state('ri-file-add-line', 'No policies yet', 'Use “New policy” to write one or start from a template.'); else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle">
          <thead><tr><th>Policy</th><th>Status</th><th>Version</th><th>Applies to</th><th>Effective</th><th style="min-width:180px">Acknowledged</th><th></th></tr></thead><tbody>
          <?php foreach ($all as $p): [$icon, $col] = policy_meta($p['category']); [$d, $t] = $p['requires_ack'] ? policy_ack_stats($p) : [0, 0]; $pct = $t ? (int) round($d / $t * 100) : 0; ?>
            <tr>
              <td><div class="d-flex align-items-center gap-2"><span class="pol-icon sm" style="--c:<?= $col ?>"><i class="<?= $icon ?>"></i></span><div><a class="fw-semibold text-body" href="<?= e(url('policies', ['a' => 'view', 'id' => $p['id']])) ?>"><?= e($p['title']) ?></a><div class="small text-muted"><?= e($p['category']) ?></div></div></div></td>
              <td><?= badge($p['status']) ?></td>
              <td>v<?= e($p['version']) ?></td>
              <td class="small"><?= e($p['dept'] ?: 'Everyone') ?></td>
              <td class="small"><?= fdate($p['effective_date']) ?><?= $p['review_date'] && $p['review_date'] < date('Y-m-d') ? '<div><span class="pill pill-warning">Review overdue</span></div>' : '' ?></td>
              <td><?php if (!$p['requires_ack']): ?><span class="small text-muted">For information</span><?php elseif ($p['status'] !== 'Published'): ?><span class="small text-muted">—</span><?php else: ?>
                <div class="pol-progress"><span style="width:<?= $pct ?>%" class="<?= $pct === 100 ? 'full' : '' ?>"></span></div><a class="small" href="<?= e(url('policies', ['a' => 'report', 'id' => $p['id']])) ?>"><?= $d ?> of <?= $t ?> · <?= $pct ?>%</a><?php endif; ?></td>
              <td class="text-end text-nowrap"><a class="btn btn-sm btn-icon" title="Edit" aria-label="Edit <?= e($p['title']) ?>" href="<?= e(url('policies', ['a' => 'edit', 'id' => $p['id']])) ?>"><i class="ri-pencil-line"></i></a>
                <?php if ($p['status'] === 'Published' && $p['requires_ack']): ?><a class="btn btn-sm btn-icon" title="Acknowledgement report" aria-label="Acknowledgement report" href="<?= e(url('policies', ['a' => 'report', 'id' => $p['id']])) ?>"><i class="ri-bar-chart-box-line"></i></a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
    <?php endif;
    page_end();
}

/* ── Reader ── */
function policy_view(array $row, array $maps): void
{
    global $manage, $me;
    $p = policy_for_user((int) $row['id']);
    [$icon, $col] = policy_meta($p['category']);
    $ack = $me ? one('SELECT * FROM hrm_policy_acks WHERE policy_id = ? AND employee_id = ? AND version = ?', [$p['id'], $me, $p['version']]) : null;
    $older = $me && !$ack ? one('SELECT version, acknowledged_at FROM hrm_policy_acks WHERE policy_id = ? AND employee_id = ? ORDER BY acknowledged_at DESC LIMIT 1', [$p['id'], $me]) : null;
    $inAudience = !$p['department_id'] || ($me && (int) val('SELECT department_id FROM hrm_employees WHERE id = ?', [$me]) === (int) $p['department_id']);
    $isPdf = $p['attachment'] && str_ends_with(strtolower($p['attachment']), '.pdf');
    $related = all("SELECT id, title, category FROM hrm_policies WHERE status = 'Published' AND id <> ? AND (department_id IS NULL OR department_id <=> ?) ORDER BY category = ? DESC, updated_at DESC LIMIT 5",
        [$p['id'], $me ? val('SELECT department_id FROM hrm_employees WHERE id = ?', [$me]) : null, $p['category']]);
    ?>
    <div class="pol-layout">
      <article class="card pol-doc" style="--c:<?= $col ?>">
        <header class="pol-doc-head">
          <span class="pol-icon lg" style="--c:<?= $col ?>"><i class="<?= $icon ?>"></i></span>
          <div class="grow">
            <div class="pol-cat"><?= e($p['category']) ?><?= $p['status'] !== 'Published' ? ' · ' . badge($p['status']) : '' ?></div>
            <h2><?= e($p['title']) ?></h2>
            <div class="pol-doc-meta">
              <span><i class="ri-git-commit-line"></i>Version <?= e($p['version']) ?></span>
              <?php if ($p['effective_date']): ?><span><i class="ri-calendar-check-line"></i>Effective <?= fdate($p['effective_date']) ?></span><?php endif; ?>
              <?php if ($p['review_date']): ?><span><i class="ri-refresh-line"></i>Next review <?= fdate($p['review_date']) ?></span><?php endif; ?>
              <span><i class="ri-group-line"></i><?= $p['dept'] ? e($p['dept']) . ' department' : 'Everyone' ?></span>
              <?php if ($p['owner']): ?><span><i class="ri-user-settings-line"></i><?= e($p['owner']) ?></span><?php endif; ?>
            </div>
          </div>
        </header>
        <?php if ($p['summary']): ?><p class="pol-lead"><?= e($p['summary']) ?></p><?php endif; ?>
        <?php if (trim((string) $p['content']) !== ''): ?><div class="pol-body"><?= policy_html($p['content']) ?></div><?php endif; ?>
        <?php if ($p['attachment']): ?>
          <div class="pol-attach">
            <div class="d-flex align-items-center gap-2 flex-wrap"><i class="<?= $isPdf ? 'ri-file-pdf-2-line text-danger' : 'ri-file-word-2-line text-primary' ?> fs-4"></i><strong class="grow">Policy document</strong>
              <a class="btn btn-sm btn-light border" href="<?= e(file_url($p['attachment'])) ?>" target="_blank" rel="noopener"><i class="ri-external-link-line me-1"></i><?= $isPdf ? 'Open' : 'Download' ?></a></div>
            <?php if ($isPdf): ?><iframe class="pol-pdf" src="<?= e(file_url($p['attachment'])) ?>" title="<?= e($p['title']) ?> (PDF)"></iframe><?php endif; ?>
          </div>
        <?php endif; ?>
        <footer class="pol-doc-foot">Last updated <?= fdate($p['updated_at'] ?: $p['created_at']) ?> · <?= e(setting('company_name')) ?></footer>
      </article>

      <aside class="vstack gap-3 no-print">
        <div class="card pol-ack-card"><div class="card-body">
          <?php if (!$p['requires_ack']): ?>
            <div class="pol-ack-state info"><i class="ri-information-line"></i><div><strong>For information</strong><span>No acknowledgement needed for this policy.</span></div></div>
          <?php elseif ($p['status'] !== 'Published'): ?>
            <div class="pol-ack-state info"><i class="ri-draft-line"></i><div><strong><?= e($p['status']) ?></strong><span>Employees can acknowledge once it is published.</span></div></div>
          <?php elseif ($ack): ?>
            <div class="pol-ack-state ok"><i class="ri-checkbox-circle-fill"></i><div><strong>You acknowledged this policy</strong><span>Version <?= e($p['version']) ?> · <?= fdate($ack['acknowledged_at'], 'd M Y, h:i A') ?></span></div></div>
          <?php elseif (!$me): ?>
            <div class="pol-ack-state info"><i class="ri-user-unfollow-line"></i><div><strong>No employee record</strong><span>Your login is not linked to an employee, so acknowledgements are not recorded.</span></div></div>
          <?php elseif (!$inAudience): ?>
            <div class="pol-ack-state info"><i class="ri-building-2-line"></i><div><strong>Not for your department</strong><span>This policy applies to <?= e($p['dept']) ?>.</span></div></div>
          <?php else: ?>
            <div class="pol-ack-state warn"><i class="ri-error-warning-fill"></i><div><strong>Action required</strong><span><?= $older ? 'This policy changed since you acknowledged version ' . e($older['version']) . '. Please read it again.' : 'Please read this policy and confirm below.' ?></span></div></div>
            <form method="post" action="<?= e(url('policies', ['a' => 'ack'])) ?>" class="mt-3" data-ack-form>
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <label class="pol-agree"><input class="form-check-input" type="checkbox" name="agree" value="1" required>
                <span>I have read and understood the <strong><?= e($p['title']) ?></strong> (version <?= e($p['version']) ?>) and agree to comply with it.</span></label>
              <button class="btn btn-primary w-100 mt-3" disabled><i class="ri-checkbox-circle-line me-1"></i>Acknowledge</button>
            </form>
            <script>(function () { var f = document.querySelector('[data-ack-form]'); if (!f) return; var c = f.querySelector('[name=agree]'), b = f.querySelector('button'); c.addEventListener('change', function () { b.disabled = !c.checked; }); })();</script>
          <?php endif; ?>
          <button type="button" class="btn btn-light border w-100 mt-2" data-print><i class="ri-printer-line me-1"></i>Print</button>
        </div></div>

        <?php if ($manage): [$d, $t] = policy_ack_stats($p); $pct = $t ? (int) round($d / $t * 100) : 0; ?>
          <div class="card"><div class="card-header-lite">Manage</div><div class="card-body vstack gap-2">
            <?php if ($p['requires_ack'] && $p['status'] === 'Published'): ?>
              <div class="d-flex justify-content-between small"><span class="text-muted">Acknowledged</span><strong><?= $d ?> / <?= $t ?></strong></div>
              <div class="pol-progress mb-1"><span style="width:<?= $pct ?>%" class="<?= $pct === 100 ? 'full' : '' ?>"></span></div>
              <a class="btn btn-light border" href="<?= e(url('policies', ['a' => 'report', 'id' => $p['id']])) ?>"><i class="ri-bar-chart-box-line me-1"></i>Acknowledgement report</a>
            <?php endif; ?>
            <?php if ($p['status'] !== 'Published'): ?>
              <?= post_button(url('policies', ['a' => 'publish', 'id' => $p['id']]), '<i class="ri-send-plane-line me-1"></i>Publish', 'btn btn-success w-100', 'Publish this policy to ' . ($p['dept'] ? 'the ' . $p['dept'] . ' department' : 'everyone') . '? Employees will see it straight away.', ['id' => $p['id']]) ?>
            <?php else: ?>
              <?= post_button(url('policies', ['a' => 'archive', 'id' => $p['id']]), '<i class="ri-archive-line me-1"></i>Archive', 'btn btn-light border w-100', 'Archive this policy? Employees will no longer see it.', ['id' => $p['id']]) ?>
            <?php endif; ?>
            <div class="small text-muted"><i class="ri-information-line"></i> To ask everyone to acknowledge again after a change, edit the policy and raise its version number.</div>
          </div></div>
        <?php endif; ?>

        <?php if ($related): ?>
          <div class="card"><div class="card-header-lite">Other policies</div>
            <?php foreach ($related as $r): [$ri, $rc] = policy_meta($r['category']); ?>
              <a class="list-row text-body" href="<?= e(url('policies', ['a' => 'view', 'id' => $r['id']])) ?>"><span class="pol-icon sm" style="--c:<?= $rc ?>"><i class="<?= $ri ?>"></i></span><div class="grow"><div class="title"><?= e($r['title']) ?></div><div class="sub"><?= e($r['category']) ?></div></div></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>
    </div>
    <?php
}

$tpl = $a === 'create' ? (policy_templates()[$_GET['template'] ?? ''] ?? null) : null;
crud([
    'route' => 'policies', 'table' => 'hrm_policies', 'title' => 'HR Policies', 'singular' => 'Policy', 'icon' => 'ri-shield-check-line',
    'can_manage' => 'manage_policies', 'order' => 'updated_at DESC',
    'title_of' => fn(array $r) => $r['title'],
    // Employees reach only published policies for everyone or their department.
    'scope' => fn() => $manage ? ['1=1', []] : ["status = 'Published' AND (department_id IS NULL OR department_id <=> ?)", [$myDept]],
    'fields' => [
        'title'          => ['label' => 'Policy title', 'required' => true, 'section' => 'Policy', 'col' => 'col-md-8', 'maxlength' => 160, 'default' => $tpl['title'] ?? null],
        'category'       => ['label' => 'Category', 'type' => 'select', 'options' => $catOptions, 'required' => true, 'col' => 'col-md-4', 'default' => $tpl['category'] ?? 'General'],
        'summary'        => ['label' => 'Summary', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-12', 'maxlength' => 300, 'default' => $tpl['summary'] ?? null,
            'placeholder' => 'One or two sentences shown on the policy card', 'help' => 'Up to 300 characters.'],
        'content'        => ['label' => 'Policy text', 'type' => 'textarea', 'rows' => 18, 'col' => 'col-12', 'section' => 'Content', 'default' => $tpl['content'] ?? null,
            'help' => 'Formatting: "## Heading", "### Sub-heading", "- bullet", "1. numbered", **bold**, *italic*, [link text](https://…). Leave a blank line between paragraphs.'],
        'attachment'     => ['label' => 'Policy document (optional)', 'type' => 'file', 'dir' => 'policies', 'accept' => '.pdf,.doc,.docx', 'col' => 'col-md-8', 'help' => 'PDF (previewed in the page) or Word, up to 5 MB.'],
        'status'         => ['label' => 'Status', 'type' => 'select', 'options' => POLICY_STATUSES, 'default' => 'Draft', 'required' => true, 'section' => 'Publishing', 'col' => 'col-md-3', 'list' => true],
        'version'        => ['label' => 'Version', 'required' => true, 'col' => 'col-md-3', 'maxlength' => 20, 'default' => '1.0', 'help' => 'Change it to ask everyone to acknowledge again.'],
        'effective_date' => ['label' => 'Effective from', 'type' => 'date', 'col' => 'col-md-3', 'default' => date('Y-m-d')],
        'review_date'    => ['label' => 'Next review', 'type' => 'date', 'col' => 'col-md-3', 'default' => date('Y-m-d', strtotime('+1 year'))],
        'department_id'  => ['label' => 'Applies to', 'type' => 'relation', 'table' => 'hrm_departments', 'col' => 'col-md-4', 'placeholder' => 'Everyone in the company'],
        'owner'          => ['label' => 'Policy owner', 'col' => 'col-md-4', 'maxlength' => 120, 'placeholder' => 'e.g. HR Department', 'default' => 'HR Department'],
        'requires_ack'   => ['label' => 'Employees must acknowledge it', 'type' => 'checkbox', 'col' => 'col-md-4', 'default' => 1],
    ],
    'before_save' => function (array $d, ?array $existing) {
        $d['version'] = trim((string) $d['version']);
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-]{0,19}$/', $d['version'])) throw new RuntimeException('Version can use letters, numbers, dots and dashes (e.g. 1.0, 2.1, 2026-A).');
        if ($d['review_date'] && $d['effective_date'] && $d['review_date'] < $d['effective_date']) throw new RuntimeException('The next review date cannot be before the effective date.');
        if ($d['status'] === 'Published' && trim((string) $d['content']) === '' && empty($d['attachment']) && empty($existing['attachment'])) {
            throw new RuntimeException('Add the policy text or attach a document before publishing.');
        }
        if (empty($d['attachment'])) unset($d['attachment']); // keep the current file when no new one is uploaded
        $nowPublished = $d['status'] === 'Published' && (!$existing || $existing['status'] !== 'Published' || $existing['version'] !== $d['version']);
        if ($nowPublished) $d['published_at'] = date('Y-m-d H:i:s');
        $d['updated_at'] = date('Y-m-d H:i:s');
        if (!$existing) $d['created_by'] = user()['id'] ?? null;
        return $d;
    },
    'index' => 'policies_index',
    'view' => 'policy_view',
]);
