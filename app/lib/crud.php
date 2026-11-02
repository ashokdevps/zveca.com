<?php
/**
 * Declarative CRUD: a module describes its table and fields, this file supplies
 * list (search / filter / pagination / CSV export), create, edit, view and delete.
 * Modules with workflows (leaves, payroll, attendance…) add their own actions on top.
 */

function crud(array $res): void
{
    $res += [
        'can_view' => null, 'can_manage' => null, 'order' => 'id DESC', 'per_page' => 20,
        'scope' => null, 'before_save' => null, 'after_save' => null, 'view' => null,
        'row_actions' => null, 'header_actions' => '', 'delete_guard' => null, 'can_edit_row' => null,
        'list_title' => null, 'crumb' => null, 'form_extra' => null, 'index' => null, 'before_list' => null,
    ];
    if ($res['can_view']) {
        authorize($res['can_view']);
    }
    $a = $_GET['a'] ?? 'index';
    switch ($a) {
        case 'create':
        case 'edit':
            crud_require_manage($res);
            crud_form($res, $a === 'edit' ? crud_find($res, int_input('id')) : null);
            break;
        case 'save':
            crud_require_manage($res);
            crud_save($res);
            break;
        case 'view':
            crud_view($res, crud_find($res, int_input('id')));
            break;
        case 'delete':
            crud_require_manage($res);
            crud_delete($res);
            break;
        case 'export':
            crud_export($res);
            break;
        default:
            $res['index'] ? ($res['index'])($res) : crud_index($res);
    }
}

function crud_can_manage(array $res): bool
{
    return !$res['can_manage'] || can($res['can_manage']);
}

function crud_require_manage(array $res): void
{
    if ($res['can_manage']) {
        authorize($res['can_manage']);
    }
}

function crud_scope(array $res): array
{
    return !empty($res['scope']) ? ($res['scope'])() : ['1=1', []];
}

function crud_find(array $res, int $id): array
{
    [$w, $p] = crud_scope($res);
    $row = one('SELECT * FROM ' . qi($res['table']) . " WHERE id = ? AND ($w)", [$id, ...$p]);
    if (!$row) {
        not_found();
    }
    return $row;
}

/** Preloaded id => label maps for relation/employee columns so lists don't N+1. */
function crud_maps(array $res): array
{
    $maps = [];
    foreach ($res['fields'] as $name => $f) {
        $t = $f['type'] ?? 'text';
        if ($t === 'employee') {
            $maps[$name] = employee_options(true);
        } elseif ($t === 'relation') {
            $maps[$name] = relation_options($f);
        }
    }
    return $maps;
}

function crud_format(string $name, array $f, $v, array $row, array $maps): string
{
    if (isset($f['format'])) {
        return ($f['format'])($v, $row);
    }
    $t = $f['type'] ?? 'text';
    if ($v === null || $v === '') {
        return '<span class="text-muted">—</span>';
    }
    if (!empty($f['badge'])) {
        return badge($v);
    }
    switch ($t) {
        case 'employee':
            $label = $maps[$name][$v] ?? '#' . $v;
            return '<a class="person-link" href="' . e(url('employees', ['a' => 'view', 'id' => $v])) . '">' . avatar(preg_replace('/\s*\(.*\)$/', '', $label), null, 'xs') . e(preg_replace('/\s*\(.*\)$/', '', $label)) . '</a>';
        case 'relation':
            return e($maps[$name][$v] ?? '—');
        case 'select':
            $opts = $f['options'] ?? [];
            return e(array_is_list($opts) ? $v : ($opts[$v] ?? $v));
        case 'date':
            return e(fdate($v));
        case 'datetime-local':
            return e(fdate($v, 'd M Y, h:i A'));
        case 'money':
            return e(money($v));
        case 'checkbox':
            return $v ? '<i class="ri-checkbox-circle-fill text-success"></i>' : '<i class="ri-close-circle-line text-muted"></i>';
        case 'color':
            return '<span class="swatch" style="background:' . e($v) . '"></span>';
        case 'file':
            return '<a href="' . e(file_url($v)) . '" target="_blank"><i class="ri-attachment-2"></i> File</a>';
        case 'email':
            return '<a href="mailto:' . e($v) . '">' . e($v) . '</a>';
        case 'textarea':
            return e(mb_strimwidth((string) $v, 0, 60, '…'));
        default:
            return e($v);
    }
}

/** Builds WHERE from search box + filter dropdowns + module scope. */
function crud_where(array $res): array
{
    [$w, $p] = crud_scope($res);
    $where = ["($w)"];
    $params = $p;
    $search = trim((string) input('q', ''));
    if ($search !== '') {
        $cols = array_keys(array_filter($res['fields'], fn($f) => !empty($f['search'])));
        if ($cols) {
            $where[] = '(' . implode(' OR ', array_map(fn($c) => qi($c) . ' LIKE ?', $cols)) . ')';
            foreach ($cols as $c) {
                $params[] = "%$search%";
            }
        }
    }
    foreach ($res['fields'] as $name => $f) {
        if (!empty($f['filter']) && ($v = input($name)) !== null && $v !== '') {
            if (isset($f['filter_sql'])) { // virtual filter: the field builds its own condition
                [$fw, $fp] = ($f['filter_sql'])($v);
                if ($fw) { $where[] = "($fw)"; array_push($params, ...$fp); }
                continue;
            }
            $where[] = qi($name) . ' = ?';
            $params[] = $v;
        }
    }
    return [implode(' AND ', $where), $params];
}

/** "Department" → "departments", "Category" → "categories" (for filter placeholders). */
function plural_label(string $label): string
{
    $l = strtolower($label);
    if (str_ends_with($l, 's')) return $l;
    if (preg_match('/[^aeiou]y$/', $l)) return substr($l, 0, -1) . 'ies';
    return $l . 's';
}

/** Real columns of a table (cached) — used to whitelist sortable columns. */
function table_columns(string $table): array
{
    static $cache = [];
    return $cache[$table] ??= array_column(all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'COLUMN_NAME');
}

/** The field shown as status tabs above a list: first filterable badge field with fixed options. */
function crud_tab_field(array $res): ?string
{
    foreach ($res['fields'] as $name => $f) {
        if (!empty($f['filter']) && !empty($f['badge']) && ($f['type'] ?? '') === 'select' && !empty($f['options'])) {
            return $name;
        }
    }
    return null;
}

function crud_index(array $res): void
{
    $tabField = crud_tab_field($res);
    $tabValue = $tabField ? (string) input($tabField, '') : '';

    // Counts per tab ignore the tab filter itself but respect search + other filters.
    $tabCounts = [];
    if ($tabField) {
        $saved = [$_GET[$tabField] ?? null, $_POST[$tabField] ?? null];
        unset($_GET[$tabField], $_POST[$tabField]);
        [$baseWhere, $baseParams] = crud_where($res);
        foreach (all('SELECT ' . qi($tabField) . ' v, COUNT(*) c FROM ' . qi($res['table']) . " WHERE $baseWhere GROUP BY v", $baseParams) as $r) {
            $tabCounts[(string) $r['v']] = (int) $r['c'];
        }
        if ($saved[0] !== null) $_GET[$tabField] = $saved[0];
        if ($saved[1] !== null) $_POST[$tabField] = $saved[1];
    }

    [$where, $params] = crud_where($res);
    $per = list_per_page($res['route'] . ':' . ($_GET['a'] ?? 'index'), $res['per_page']);
    $page = max(1, int_input('page', 1));

    // Sorting: only real columns that are shown in the list.
    $listCols = array_filter($res['fields'], fn($f) => !empty($f['list']));
    $sortable = array_values(array_intersect(array_keys($listCols), table_columns($res['table'])));
    // Virtual columns may sort by an SQL expression ('sort_sql' returns it, or null when not sortable right now).
    $sortExpr = [];
    foreach ($listCols as $n => $f) if (isset($f['sort_sql']) && ($x = ($f['sort_sql'])())) { $sortable[] = $n; $sortExpr[$n] = $x; }
    $sort = in_array(input('sort'), $sortable, true) ? input('sort') : null;
    $dir = input('dir') === 'desc' ? 'desc' : 'asc';
    $order = $sort ? ($sortExpr[$sort] ?? qi($sort)) . ' ' . strtoupper($dir) . ', id DESC' : $res['order'];

    $total = (int) val('SELECT COUNT(*) FROM ' . qi($res['table']) . " WHERE $where", $params);
    $page = min($page, max(1, (int) ceil($total / $per))); // a page number past the end (after filtering) shows the last page
    $rows = all('SELECT * FROM ' . qi($res['table']) . " WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $maps = crud_maps($res);
    $manage = crud_can_manage($res);

    $sub = ($_GET['a'] ?? 'index') !== 'index' ? $_GET['a'] : null; // e.g. "my" views built on the list
    $query = array_filter(['r' => $res['route'], 'a' => $sub, 'q' => input('q'), 'sort' => $sort, 'dir' => $sort ? $dir : null]
        + array_map(fn($n) => input($n), array_combine(array_keys($res['fields']), array_keys($res['fields']))), fn($v) => $v !== null && $v !== '');
    $filtered = input('q') || array_filter(array_keys($res['fields']), fn($n) => !empty($res['fields'][$n]['filter']) && $n !== $tabField && input($n) !== null && input($n) !== '');

    $actions = $res['header_actions']
        . '<a class="btn btn-light border" href="' . e(url($res['route'], array_merge($query, ['a' => 'export']))) . '"><i class="ri-download-2-line me-1"></i>Export</a>'
        . ($manage ? '<a class="btn btn-primary" href="' . e(url($res['route'], ['a' => 'create'])) . '"><i class="ri-add-line me-1"></i>Add ' . e($res['singular']) . '</a>' : '');

    page_start($res['list_title'] ?? $res['title'], [$res['title']], $actions);
    if ($res['before_list']) {
        ($res['before_list'])();
    }
    $link = fn(array $over) => url($res['route'], array_filter(array_merge($query, $over, ['page' => null]), fn($v) => $v !== null && $v !== ''));
    ?>
    <div class="card list-card">
      <?php if ($tabField): $opts = $res['fields'][$tabField]['options']; ?>
        <nav class="list-tabs" aria-label="Filter by <?= e(strtolower($res['fields'][$tabField]['label'])) ?>">
          <a class="<?= $tabValue === '' ? 'active' : '' ?>" href="<?= e($link([$tabField => null])) ?>">All <span><?= indian_number(array_sum($tabCounts)) ?></span></a>
          <?php foreach ($opts as $opt): ?>
            <a class="<?= $tabValue === (string) $opt ? 'active' : '' ?>" href="<?= e($link([$tabField => $opt])) ?>"><?= e($opt) ?> <span><?= indian_number($tabCounts[(string) $opt] ?? 0) ?></span></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/">
        <input type="hidden" name="r" value="<?= e($res['route']) ?>">
        <?php foreach (['a' => $sub, $tabField => $tabValue, 'sort' => $sort, 'dir' => $sort ? $dir : null] as $k => $v): if ($k && $v !== null && $v !== ''): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
        <?php endif; endforeach; ?>
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Search <?= e(strtolower($res['title'])) ?>… (Enter)">
          <?php if (input('q')): ?><a class="search-clear" href="<?= e($link(['q' => null])) ?>" aria-label="Clear search"><i class="ri-close-line"></i></a><?php endif; ?></div>
        <?php foreach ($res['fields'] as $name => $f): if (empty($f['filter']) || $name === $tabField) continue;
            $opts = ($f['type'] ?? '') === 'relation' ? $maps[$name] : (($f['type'] ?? '') === 'employee' ? $maps[$name] : ($f['options'] ?? []));
            $isList = array_is_list($opts); ?>
          <select class="form-select" name="<?= e($name) ?>" onchange="this.form.submit()" aria-label="Filter by <?= e(strtolower($f['label'])) ?>">
            <option value=""><?= e($f['filter_label'] ?? 'All ' . plural_label($f['label'])) ?></option>
            <?php foreach ($opts as $k => $v): $k = $isList ? $v : $k; ?>
              <option value="<?= e($k) ?>" <?= (string) input($name) === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endforeach; ?>
        <?php if ($filtered): ?><a class="btn btn-link px-1" href="<?= e(url($res['route'], array_filter(['a' => $sub, $tabField => $tabValue ?: null]))) ?>"><i class="ri-filter-off-line me-1"></i>Clear filters</a><?php endif; ?>
        <span class="ms-auto small text-muted result-count"><?= indian_number($total) ?> <?= $total === 1 ? "record" : "records" ?></span>
      </form>
      <?php if (!$rows): ?>
        <?php empty_state($res['icon'], 'No ' . strtolower($res['title']) . ' found', $filtered || $tabValue ? 'Try a different search or filter.' : 'Records you add will appear here.',
            $manage && !$filtered ? '<a class="btn btn-primary" href="' . e(url($res['route'], ['a' => 'create'])) . '"><i class="ri-add-line me-1"></i>Add ' . e($res['singular']) . '</a>' : ''); ?>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle data-table" data-grid="<?= e($res['route']) ?>">
          <thead><tr>
            <?php foreach ($listCols as $name => $f): ?>
              <th<?= $sort === $name ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                <?php if (in_array($name, $sortable, true)): $next = $sort === $name && $dir === 'asc' ? 'desc' : 'asc'; ?>
                  <a class="th-sort<?= $sort === $name ? ' on' : '' ?>" href="<?= e($link(['sort' => $name, 'dir' => $next])) ?>"><?= e($f['label']) ?>
                    <i class="<?= $sort === $name ? ($dir === 'asc' ? 'ri-arrow-up-line' : 'ri-arrow-down-line') : 'ri-arrow-up-down-line' ?>"></i></a>
                <?php else: ?><?= e($f['label']) ?><?php endif; ?>
              </th>
            <?php endforeach; ?>
            <th class="text-end">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $row):
              $canEdit = $manage && (!$res['can_edit_row'] || ($res['can_edit_row'])($row));
              $viewUrl = url($res['route'], ['a' => 'view', 'id' => $row['id']]); ?>
            <tr class="row-link" data-href="<?= e($viewUrl) ?>">
              <?php $first = true; foreach ($listCols as $name => $f): ?>
                <td><?php
                  $cell = crud_format($name, $f, $row[$name] ?? null, $row, $maps);
                  if ($first) {
                      echo '<a class="fw-semibold" href="' . e($viewUrl) . '">' . strip_tags($cell, '<span><i><img><strong><small>') . '</a>';
                  } else {
                      // Long values stay on one line (full text on hover) so large lists remain scannable.
                      $plain = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES, 'UTF-8'));
                      echo mb_strlen($plain) > 22 ? '<div class="cell-clip" title="' . e($plain) . '">' . $cell . '</div>' : $cell;
                  }
                  $first = false; ?></td>
              <?php endforeach; ?>
              <td class="text-end text-nowrap">
                <?= $res['row_actions'] ? ($res['row_actions'])($row) : '' ?>
                <a class="btn btn-sm btn-icon" title="View" href="<?= e($viewUrl) ?>"><i class="ri-eye-line"></i></a>
                <?php if ($canEdit): ?>
                  <a class="btn btn-sm btn-icon" title="Edit" href="<?= e(url($res['route'], ['a' => 'edit', 'id' => $row['id']])) ?>"><i class="ri-pencil-line"></i></a>
                  <?= post_button(url($res['route'], ['a' => 'delete', 'id' => $row['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Delete this ' . strtolower($res['singular']) . '? This cannot be undone.') ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($total, $page, $per, $query, true) ?>
      <?php endif; ?>
    </div>
    <?php
    page_end();
}

function crud_form(array $res, ?array $row): void
{
    if ($row && $res['can_edit_row'] && !($res['can_edit_row'])($row)) {
        authorize('__never__');
    }
    $isNew = !$row;
    $title = ($isNew ? 'Add ' : 'Edit ') . $res['singular'];
    page_start($title, [$res['title'] => url($res['route']), $title]);
    $hasFile = (bool) array_filter($res['fields'], fn($f) => ($f['type'] ?? '') === 'file');
    ?>
    <?php
    // Split fields into titled sections; each section renders as its own card.
    $sections = [];
    $current = $res['singular'] . ' details';
    foreach ($res['fields'] as $name => $f) {
        if (isset($f['form']) && !$f['form']) continue;
        if (isset($f['section'])) $current = $f['section'];
        $sections[$current][$name] = $f;
    }
    $extra = $res['form_extra'] ? ($res['form_extra'])($row) : '';
    ?>
    <form class="form-stack" method="post" action="<?= e(url($res['route'], ['a' => 'save'])) ?>" <?= $hasFile ? 'enctype="multipart/form-data"' : '' ?>>
      <?= csrf_field() ?>
      <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><?php endif; ?>
      <?php foreach ($sections as $title => $fields): ?>
        <section class="card form-block">
          <div class="form-block-head"><span class="fb-icon"><i class="<?= section_icon($title) ?>"></i></span><div><h6><?= e($title) ?></h6><small><?= count($fields) ?> field<?= count($fields) > 1 ? 's' : '' ?><?= array_filter($fields, fn($f) => !empty($f['required'])) ? ' · <span class="text-danger">*</span> required' : '' ?></small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?php foreach ($fields as $name => $f) {
                $value = old($name, $row[$name] ?? ($isNew ? ($f['default'] ?? null) : null));
                echo form_field($name, $f, $value);
            } ?>
          </div></div>
        </section>
      <?php endforeach; ?>
      <?php if (trim(strip_tags($extra, '<input><select>')) !== '' && !str_starts_with(ltrim($extra), '<section')): /* a module's own <section> card is printed as is */ ?>
        <section class="card form-block"><div class="form-block-body pt-0"><?= $extra ?></div></section>
      <?php else: echo $extra; endif; ?>
      <div class="form-actions sticky-actions">
        <span class="text-muted small d-none d-md-inline"><i class="ri-information-line"></i> Fields marked <span class="text-danger">*</span> are required.</span>
        <a class="btn btn-light border ms-auto" href="<?= e($isNew ? url($res['route']) : url($res['route'], ['a' => 'view', 'id' => $row['id']])) ?>">Cancel</a>
        <button class="btn btn-primary"><i class="ri-save-3-line me-1"></i><?= $isNew ? 'Create ' . e(strtolower($res['singular'])) : 'Save changes' ?></button>
      </div>
    </form>
    <?php
    page_end();
}

/** Icon for a form section title. */
function section_icon(string $title): string
{
    $map = ['personal' => 'ri-user-3-line', 'job' => 'ri-briefcase-4-line', 'statutory' => 'ri-bank-line', 'bank' => 'ri-bank-line',
        'emergency' => 'ri-first-aid-kit-line', 'candidate' => 'ri-user-search-line', 'experience' => 'ri-award-line',
        'evaluation' => 'ri-star-smile-line', 'portal' => 'ri-shield-keyhole-line'];
    foreach ($map as $k => $icon) {
        if (stripos($title, $k) !== false) return $icon;
    }
    return 'ri-file-list-3-line';
}

/** Collects + validates posted values for the resource's form fields. */
function crud_collect(array $res, ?array $existing): array
{
    $data = [];
    $errors = [];
    foreach ($res['fields'] as $name => $f) {
        if ((isset($f['form']) && !$f['form']) || !empty($f['readonly'])) {
            continue;
        }
        $t = $f['type'] ?? 'text';
        if ($t === 'file') {
            try {
                $path = store_upload($name, $f['dir']);
                if ($path) {
                    $data[$name] = $path;
                } elseif (!empty($f['required']) && empty($existing[$name])) {
                    $errors[] = $f['label'] . ' is required.';
                }
            } catch (RuntimeException $ex) {
                $errors[] = $f['label'] . ': ' . $ex->getMessage();
            }
            continue;
        }
        $v = $_POST[$name] ?? null;
        $v = is_string($v) ? trim($v) : $v;
        if ($t === 'checkbox') {
            $data[$name] = $v ? 1 : 0;
            continue;
        }
        if ($v === '' || $v === null) {
            if (!empty($f['required'])) {
                $errors[] = $f['label'] . ' is required.';
            }
            $data[$name] = null;
            continue;
        }
        if ($t === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $f['label'] . ' must be a valid e-mail address.';
        } elseif ($t === 'date' && !valid_date($v)) {
            $errors[] = $f['label'] . ' must be a valid date.';
        } elseif (in_array($t, ['number', 'money'], true) && !is_numeric($v)) {
            $errors[] = $f['label'] . ' must be a number.';
        } elseif ($t === 'select') {
            $opts = $f['options'] ?? [];
            $allowed = array_is_list($opts) ? $opts : array_map('strval', array_keys($opts));
            if (!in_array((string) $v, $allowed, true)) {
                $errors[] = $f['label'] . ' has an invalid value.';
            }
        } elseif ($t === 'datetime-local') {
            $v = date('Y-m-d H:i:s', strtotime($v));
        }
        if (in_array($t, ['relation', 'employee'], true)) {
            $v = (int) $v;
        }
        $data[$name] = $v;
    }
    return [$data, $errors];
}

function crud_save(array $res): void
{
    verify_csrf();
    $id = int_input('id');
    $existing = $id ? crud_find($res, $id) : null;
    if ($existing && $res['can_edit_row'] && !($res['can_edit_row'])($existing)) {
        authorize('__never__');
    }
    [$data, $errors] = crud_collect($res, $existing);
    if (!$errors && $res['before_save']) {
        try {
            $data = ($res['before_save'])($data, $existing);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    if ($errors) {
        $_SESSION['_old'] = $_POST;
        flash('danger', '<strong>Please fix the following:</strong><br>' . implode('<br>', array_map('e', $errors)));
        redirect(url($res['route'], $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'create']));
    }
    if ($existing) {
        update($res['table'], $data, $id);
    } else {
        $id = insert($res['table'], $data);
    }
    if ($res['after_save']) {
        ($res['after_save'])($id, $data, !$existing);
    }
    // Audit trail: for updates, record which fields changed (old → new).
    $details = null;
    if ($existing) {
        $maps = crud_maps($res);
        $changes = [];
        foreach ($data as $col => $new) {
            $f = $res['fields'][$col] ?? null;
            if (!$f || in_array($f['type'] ?? '', ['file', 'textarea', 'password'], true)) continue;
            $old = $existing[$col] ?? null;
            if ((string) $old === (string) $new || (is_numeric($old) && is_numeric($new) && (float) $old === (float) $new)) continue;
            $show = fn($v) => $v === null || $v === '' ? '—' : (isset($maps[$col]) ? preg_replace('/\s*\(.*\)$/', '', $maps[$col][$v] ?? $v) : (is_numeric($v) ? (string) (float) $v : (string) $v));
            $changes[] = $f['label'] . ': ' . $show($old) . ' → ' . $show($new);
        }
        foreach (array_keys($data) as $col) {
            if (($res['fields'][$col]['type'] ?? '') === 'textarea' && (string) ($existing[$col] ?? '') !== (string) $data[$col]) $changes[] = $res['fields'][$col]['label'] . ' edited';
        }
        if (!$changes) {
            $changes[] = 'no field changes';
        }
        $details = mb_strimwidth(implode(', ', $changes), 0, 250, '…');
    }
    log_activity($existing ? 'updated' : 'created', $res['singular'], $id, $details);
    flash('success', e($res['singular']) . ($existing ? ' updated.' : ' created.'));
    redirect(url($res['route'], ['a' => 'view', 'id' => $id]));
}

function crud_delete(array $res): void
{
    if (!is_post()) {
        redirect(url($res['route']));
    }
    verify_csrf();
    $row = crud_find($res, int_input('id'));
    if ($res['can_edit_row'] && !($res['can_edit_row'])($row)) {
        authorize('__never__');
    }
    if ($res['delete_guard'] && ($err = ($res['delete_guard'])($row))) {
        flash('danger', e($err));
        back(url($res['route']));
    }
    try {
        q('DELETE FROM ' . qi($res['table']) . ' WHERE id = ?', [$row['id']]);
    } catch (PDOException $ex) {
        flash('danger', 'This ' . e(strtolower($res['singular'])) . ' is linked to other records and cannot be deleted.');
        back(url($res['route']));
    }
    log_activity('deleted', $res['singular'], (int) $row['id']);
    flash('success', e($res['singular']) . ' deleted.');
    redirect(url($res['route']));
}

/**
 * Plain-text title of a record (page heading, breadcrumb, browser tab). A list column with its own 'format' renders rich
 * HTML (avatar initials + name + e-mail), which must never become the title: stripping its tags glues the parts together
 * ("PSPriya Sharmapriya@example.com"). Use the stored value instead; relations / selects show their label.
 */
function crud_plain_title(array $res, array $row, array $maps): string
{
    $name = array_key_first($res['fields']);
    $f = $res['fields'][$name];
    $v = $row[$name] ?? '';
    if (isset($f['format']) || !in_array($f['type'] ?? 'text', ['relation', 'employee', 'select'], true)) {
        $t = trim(preg_replace('/\s+/', ' ', (string) $v));
    } else {
        $t = trim(html_entity_decode(strip_tags(crud_format($name, $f, $v, $row, $maps)), ENT_QUOTES, 'UTF-8'));
    }
    return $t !== '' ? $t : $res['singular'] . ' #' . (int) $row['id'];
}

function crud_view(array $res, array $row): void
{
    $maps = crud_maps($res);
    $titleField = array_key_first($res['fields']);
    $title = isset($res['title_of']) ? ($res['title_of'])($row) : crud_plain_title($res, $row, $maps);
    $manage = crud_can_manage($res) && (!$res['can_edit_row'] || ($res['can_edit_row'])($row));
    $actions = $manage
        ? '<a class="btn btn-light border" href="' . e(url($res['route'], ['a' => 'edit', 'id' => $row['id']])) . '"><i class="ri-pencil-line me-1"></i>Edit</a>'
          . post_button(url($res['route'], ['a' => 'delete', 'id' => $row['id']]), '<i class="ri-delete-bin-6-line me-1"></i>Delete', 'btn btn-outline-danger', 'Delete this record? This cannot be undone.')
        : '';
    page_start($title, [$res['title'] => url($res['route']), $title], $actions);
    if ($res['view']) {
        ($res['view'])($row, $maps);
        echo '<div class="mt-3 no-print">';
        record_history_card($res['singular'], (int) $row['id'], $row);
        echo '</div>';
    } else {
        echo '<div class="grid grid-3"><div class="span-2">';
        crud_detail_card($res, $row, $maps);
        echo '</div><div>';
        record_history_card($res['singular'], (int) $row['id'], $row);
        echo '</div></div>';
    }
    page_end();
}

/** Human wording for activity-log actions. */
function activity_phrase(string $action, ?string $details): string
{
    $map = ['created' => 'created this record', 'updated' => 'updated the details', 'deleted' => 'deleted a record',
        'client_feedback' => 'client gave feedback', 'imported' => 'imported records', 'salary_updated' => 'updated the salary structure',
        'profile_updated' => 'updated their profile', 'leave_applied' => 'applied for leave', 'leave_approved' => 'approved the leave',
        'leave_rejected' => 'rejected the leave', 'leave_cancelled' => 'cancelled the leave', 'punch_in' => 'punched in', 'punch_out' => 'punched out'];
    if (str_starts_with($action, 'stage_')) {
        return 'moved to ' . ucfirst(substr($action, 6));
    }
    if (str_starts_with($action, 'expense_')) {
        return 'marked the claim ' . substr($action, 8);
    }
    if ($action === 'updated' && $details && $details !== 'no field changes') {
        return 'updated ' . $details;
    }
    return ($map[$action] ?? str_replace('_', ' ', $action)) . ($details && !in_array($action, ['created', 'updated'], true) ? ' · ' . $details : '');
}

/** "Record info + history" card: created/updated stamps and the audit trail for one record. */
function record_history_card(string $entity, int $id, array $row = []): void
{
    $events = all('SELECT l.action, l.details, l.created_at, u.username, e.first_name, e.last_name, e.photo FROM hrm_activity_log l
                   LEFT JOIN it_login u ON u.id = l.user_id LEFT JOIN hrm_employees e ON e.id = u.employee_id
                   WHERE LOWER(l.entity) = LOWER(?) AND l.entity_id = ? ORDER BY l.id DESC LIMIT 15', [$entity, $id]);
    ?>
    <div class="card"><div class="card-header-lite"><span><i class="ri-history-line text-primary me-1"></i>Record history</span><small class="text-muted">#<?= $id ?></small></div>
      <div class="card-body">
        <?php if (!empty($row['created_at']) || !empty($row['updated_at'])): ?>
          <div class="d-flex flex-wrap gap-3 small mb-3 pb-3 border-bottom">
            <?php if (!empty($row['created_at'])): ?><span><span class="text-muted">Created</span><br><strong><?= fdate($row['created_at'], 'd M Y, h:i A') ?></strong></span><?php endif; ?>
            <?php if (!empty($row['updated_at'])): ?><span><span class="text-muted">Last updated</span><br><strong><?= time_ago($row['updated_at']) ?></strong></span><?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if (!$events): ?>
          <p class="text-muted small m-0">No changes recorded yet. Every create, update and status change will appear here.</p>
        <?php else: ?>
          <ul class="timeline history">
            <?php foreach ($events as $ev): $who = $ev['first_name'] ? trim($ev['first_name'] . ' ' . $ev['last_name']) : ($ev['username'] ?? 'System'); ?>
              <li><div class="small"><strong><?= e($who) ?></strong> <?= e(activity_phrase($ev['action'], $ev['details'])) ?></div>
                <div class="small text-muted" title="<?= e(fdate($ev['created_at'], 'd M Y, h:i A')) ?>"><?= time_ago($ev['created_at']) ?></div></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

function crud_detail_card(array $res, array $row, array $maps, string $heading = 'Details'): void
{
    echo '<div class="card"><div class="card-header-lite">' . e($heading) . '</div><div class="card-body"><dl class="detail-grid">';
    foreach ($res['fields'] as $name => $f) {
        if (!empty($f['hide_in_view'])) continue;
        echo '<div class="' . (($f['type'] ?? '') === 'textarea' ? 'span-all' : '') . '"><dt>' . e($f['label']) . '</dt><dd>'
            . (($f['type'] ?? '') === 'textarea' ? nl2br(e($row[$name] ?? '—')) : crud_format($name, $f, $row[$name] ?? null, $row, $maps)) . '</dd></div>';
    }
    echo '</dl></div></div>';
}

function crud_export(array $res): void
{
    [$where, $params] = crud_where($res);
    $rows = all('SELECT * FROM ' . qi($res['table']) . " WHERE $where ORDER BY {$res['order']}", $params);
    $maps = crud_maps($res);
    $fields = array_filter($res['fields'], fn($f) => ($f['type'] ?? '') !== 'file' && !isset($f['filter_sql'])); // filter-only virtual fields have no value
    $out = [];
    foreach ($rows as $row) {
        $line = [];
        foreach ($fields as $name => $f) {
            $v = $row[$name] ?? '';
            if (!array_key_exists($name, $row) && isset($f['format'])) {
                // computed column (e.g. a score or a count): export its text
                $v = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($f['format'])(null, $row)), ENT_QUOTES, 'UTF-8')));
            } elseif (isset($maps[$name])) {
                $v = $maps[$name][$v] ?? '';
            }
            $line[] = $v;
        }
        $out[] = $line;
    }
    send_csv(strtolower(str_replace(' ', '-', $res['title'])) . '-' . date('Y-m-d') . '.csv', array_column(array_values($fields), 'label'), $out);
}

function send_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ and names correctly
    fputcsv($fh, $headers);
    foreach ($rows as $r) {
        // Neutralise spreadsheet formula injection.
        fputcsv($fh, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));
    }
    fclose($fh);
    exit;
}
