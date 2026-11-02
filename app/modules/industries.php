<?php
/**
 * Industry master (Recruitment → Industries): the hiring industries vendors are scored in and jobs are tagged with.
 * Everyone with Sub-vendors / Recruitment can see the list; adding, renaming or deactivating needs "manage_vendor_scores".
 * An industry with scores or jobs can't be deleted (set it Inactive instead), so score history stays intact.
 */

$usage = [];
foreach (all('SELECT industry_id, COUNT(*) n, ROUND(AVG(score)) avg_score, MAX(score) best FROM hrm_vendor_scores GROUP BY industry_id') as $r) $usage[(int) $r['industry_id']] = $r;
$jobsBy = array_column(all("SELECT industry_id, COUNT(*) n FROM hrm_jobs WHERE industry_id IS NOT NULL AND status = 'Open' GROUP BY industry_id"), 'n', 'industry_id');

crud([
    'route' => 'industries', 'table' => 'hrm_industries', 'title' => 'Industries', 'singular' => 'Industry', 'icon' => 'ri-building-4-line',
    'order' => "status = 'Active' DESC, name",
    'can_manage' => 'manage_vendor_scores',
    'fields' => [
        'name'        => ['label' => 'Industry', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8', 'placeholder' => 'e.g. IT & Software, Oil & Gas, BFSI'],
        'status'      => ['label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Inactive'], 'default' => 'Active', 'required' => true, 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'description' => ['label' => 'Description', 'col' => 'col-12', 'list' => false, 'placeholder' => 'What this industry covers (optional)'],
        'vendors'     => ['label' => 'Vendors scored', 'form' => false, 'list' => true, 'hide_in_view' => true,
            'format' => fn($v, $r) => isset($usage[(int) $r['id']]) ? '<a href="' . url('vendors', ['industry' => $r['id'], 'sort' => 'perf_score', 'dir' => 'desc']) . '">' . (int) $usage[(int) $r['id']]['n'] . ' vendor(s)</a>' : '<span class="text-muted small">None</span>'],
        'avg'         => ['label' => 'Average · best score', 'form' => false, 'list' => true, 'hide_in_view' => true,
            'format' => fn($v, $r) => isset($usage[(int) $r['id']]) ? score_badge((int) $usage[(int) $r['id']]['avg_score'], 'sm') . ' <small class="text-muted">best ' . (int) $usage[(int) $r['id']]['best'] . '</small>' : '—'],
        'jobs'        => ['label' => 'Open jobs', 'form' => false, 'list' => true, 'hide_in_view' => true, 'format' => fn($v, $r) => (int) ($jobsBy[$r['id']] ?? 0)],
    ],
    'before_save' => function (array $d, ?array $existing) {
        $d['name'] = trim(preg_replace('/\s+/', ' ', $d['name']));
        if (val('SELECT id FROM hrm_industries WHERE LOWER(name) = LOWER(?) AND id <> ?', [$d['name'], $existing['id'] ?? 0])) throw new RuntimeException('This industry already exists.');
        if (!$existing) $d['created_by'] = user()['id'] ?? null;
        return $d;
    },
    'delete_guard' => fn($r) => val('SELECT COUNT(*) FROM hrm_vendor_scores WHERE industry_id = ?', [$r['id']]) || val('SELECT COUNT(*) FROM hrm_jobs WHERE industry_id = ?', [$r['id']])
        ? 'Vendors are scored in this industry or jobs use it. Set it to Inactive instead, so the score history is kept.' : null,
    'view' => function (array $ind) {
        $rows = all('SELECT s.*, v.name vendor, v.status vstatus FROM hrm_vendor_scores s JOIN hrm_vendors v ON v.id = s.vendor_id WHERE s.industry_id = ? ORDER BY s.score DESC, v.name', [$ind['id']]);
        ?>
        <div class="card"><div class="card-header-lite"><span>Vendor ranking: <?= e($ind['name']) ?></span><?= badge($ind['status']) ?></div>
          <?php if ($ind['description']): ?><div class="card-body small text-muted pb-0"><?= e($ind['description']) ?></div><?php endif; ?>
          <?php if (!$rows): ?><div class="card-body small text-muted">No vendor is scored in this industry yet. Open a vendor and add a score.</div><?php endif; ?>
          <?php foreach ($rows as $i => $r): ?>
            <a class="list-row text-body" href="<?= url('vendors', ['a' => 'view', 'id' => $r['vendor_id'], 'industry' => $ind['id']]) ?>">
              <span class="rank-no"><?= $i + 1 ?></span><?= avatar($r['vendor'], null, 'sm') ?>
              <div class="grow"><div class="title"><?= e($r['vendor']) ?></div><div class="sub"><?= e($r['note'] ?: 'Updated ' . time_ago($r['updated_at'] ?: $r['created_at'])) ?></div></div>
              <?= score_badge((int) $r['score']) ?></a>
          <?php endforeach; ?>
        </div>
        <?php
    },
]);
