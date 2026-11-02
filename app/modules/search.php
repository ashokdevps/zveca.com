<?php
/** Global search across the records the user is allowed to see. */

$q = trim((string) input('q'));
$like = "%$q%";
$groups = [];

if (mb_strlen($q) >= 2) {
    $emps = all("SELECT e.id, e.first_name, e.last_name, e.photo, e.emp_code, e.email, g.title des FROM hrm_employees e LEFT JOIN hrm_designations g ON g.id = e.designation_id
                 WHERE (CONCAT(e.first_name,' ',COALESCE(e.last_name,'')) LIKE ? OR e.emp_code LIKE ? OR e.email LIKE ? OR e.phone LIKE ?) LIMIT 10", [$like, $like, $like, $like]);
    if (can_any('view_directory', 'view_employees')) $groups['People'] = array_map(fn($r) => [
        'href' => can('view_employees') ? url('employees', ['a' => 'view', 'id' => $r['id']]) : url('directory', ['q' => emp_name($r)]),
        'title' => emp_name($r), 'sub' => trim(($r['des'] ?? '') . ' · ' . $r['emp_code'], ' ·'), 'avatar' => avatar(emp_name($r), $r['photo'], 'sm')], $emps);

    if (can('manage_recruitment')) {
        $groups['Candidates'] = array_map(fn($r) => ['href' => url('candidates', ['a' => 'view', 'id' => $r['id']]), 'title' => $r['name'], 'sub' => $r['stage'] . ($r['designation'] ? ' · ' . $r['designation'] : ''), 'avatar' => avatar($r['name'], null, 'sm')],
            all('SELECT id, name, stage, designation FROM hrm_candidates WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? OR key_skills LIKE ? LIMIT 10', [$like, $like, $like, $like]));
        $groups['Jobs'] = array_map(fn($r) => ['href' => url('jobs', ['a' => 'view', 'id' => $r['id']]), 'title' => $r['title'], 'sub' => $r['status'], 'icon' => 'ri-briefcase-4-line'],
            all('SELECT id, title, status FROM hrm_jobs WHERE title LIKE ? LIMIT 5', [$like]));
    }
    $me = my_employee_id() ?? 0;
    $projSql = org_wide() ? 'name LIKE ?' : '(name LIKE ? AND (manager_id = ? OR id IN (SELECT project_id FROM hrm_project_members WHERE employee_id = ?)))';
    if (can_any('view_projects', 'manage_projects')) $groups['Projects'] = array_map(fn($r) => ['href' => url('projects', ['a' => 'view', 'id' => $r['id']]), 'title' => $r['name'], 'sub' => $r['status'], 'icon' => 'ri-folder-chart-line'],
        all("SELECT id, name, status FROM hrm_projects WHERE $projSql LIMIT 8", org_wide() ? [$like] : [$like, $me, $me]));
    if (can('manage_accounts')) {
        $groups['Clients'] = array_map(fn($r) => ['href' => url('clients', ['a' => 'view', 'id' => $r['id']]), 'title' => $r['name'], 'sub' => $r['contact_person'] ?? '', 'icon' => 'ri-shake-hands-line'],
            all('SELECT id, name, contact_person FROM hrm_clients WHERE name LIKE ? OR contact_person LIKE ? OR email LIKE ? LIMIT 5', [$like, $like, $like]));
        $groups['Invoices'] = array_map(fn($r) => ['href' => url('invoices', ['a' => 'view', 'id' => $r['id']]), 'title' => $r['invoice_no'], 'sub' => $r['status'], 'icon' => 'ri-file-list-3-line'],
            all('SELECT id, invoice_no, status FROM hrm_invoices WHERE invoice_no LIKE ? LIMIT 5', [$like]));
    }
    // Jump straight to a page by name.
    $pages = [];
    foreach (nav_items() as $item) {
        if (isset($item['label']) && nav_visible($item) && stripos($item['label'], $q) !== false) {
            $pages[] = ['href' => url($item['route'], $item['params'] ?? []), 'title' => $item['label'], 'sub' => 'Page', 'icon' => $item['icon']];
        }
    }
    $groups = ['Pages' => $pages] + $groups;
    // Single exact hit → go there directly.
    $hits = $groups ? array_merge(...array_values($groups)) : [];
    if (count($hits) === 1) redirect($hits[0]['href']);
}

page_start('Search', ['Search']);
$total = array_sum(array_map('count', $groups));
?>
<form class="card toolbar mb-3" method="get" action="<?= base_path() ?>/dashboard/">
  <input type="hidden" name="r" value="search">
  <div class="search-box" style="max-width:none"><i class="ri-search-line"></i><input class="form-control form-control-lg" type="search" name="q" value="<?= e($q) ?>" placeholder="Search people, candidates, projects, clients, pages…" autofocus></div>
  <button class="btn btn-primary">Search</button>
</form>
<?php if (mb_strlen($q) < 2): ?>
  <div class="card"><?php empty_state('ri-search-2-line', 'Type at least 2 characters', 'Tip: press / anywhere to jump to search.'); ?></div>
<?php elseif (!$total): ?>
  <div class="card"><?php empty_state('ri-search-2-line', 'No results for “' . $q . '”', 'Check the spelling or try a shorter term.'); ?></div>
<?php else: ?>
  <div class="grid grid-2">
    <?php foreach ($groups as $label => $items): if (!$items) continue; ?>
      <div class="card"><div class="card-header-lite"><?= e($label) ?> <small class="text-muted"><?= count($items) ?></small></div>
        <?php foreach ($items as $it): ?>
          <a class="list-row text-body" href="<?= e($it['href']) ?>"><?= $it['avatar'] ?? '<div class="stat-icon" style="width:32px;height:32px;font-size:1rem;background:var(--brand-50);color:var(--brand)"><i class="' . $it['icon'] . '"></i></div>' ?>
            <div class="grow"><div class="title"><?= e($it['title']) ?></div><div class="sub"><?= e($it['sub']) ?></div></div><i class="ri-arrow-right-s-line text-muted"></i></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif;
page_end();
