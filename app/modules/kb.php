<?php
/** Knowledge base articles (support team). Published articles show in the Help Center and power the AI assistant. */

crud([
    'route' => 'kb', 'table' => 'hrm_kb_articles', 'title' => 'Knowledge Base', 'singular' => 'Article', 'icon' => 'ri-book-open-line',
    'can_view' => 'manage_support', 'can_manage' => 'manage_support', 'order' => 'category, title',
    'fields' => [
        'title'     => ['label' => 'Title', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8', 'placeholder' => 'Phrase it the way people ask, e.g. "Why was I marked late?"'],
        'published' => ['label' => 'Published', 'type' => 'checkbox', 'default' => 1, 'list' => true, 'col' => 'col-md-4'],
        'category'  => ['label' => 'Category', 'type' => 'select', 'options' => ticket_categories('agent'), 'default' => 'General', 'required' => true, 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'audience'  => ['label' => 'Visible to', 'type' => 'select', 'options' => ['Everyone', 'Staff', 'Clients', 'Vendors', 'Candidates'], 'default' => 'Everyone', 'required' => true, 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'tags'      => ['label' => 'Search keywords', 'search' => true, 'col' => 'col-md-4', 'placeholder' => 'e.g. late, grace, punch', 'help' => 'Extra words people might type.'],
        'body'      => ['label' => 'Article', 'type' => 'textarea', 'rows' => 14, 'required' => true, 'search' => true, 'col' => 'col-12',
            'help' => 'Supports **bold**, *italic*, lists (- item / 1. step) and [links](https://…).'],
        'views'     => ['label' => 'Views', 'form' => false, 'list' => true],
        'helpful'   => ['label' => 'Helpful', 'form' => false, 'list' => true,
            'format' => fn($v, $r) => ($v + $r['not_helpful']) ? '<span class="text-success">' . (int) $v . ' 👍</span> <span class="text-muted">' . (int) $r['not_helpful'] . ' 👎</span>' : '<span class="text-muted">—</span>'],
    ],
    'before_save' => function (array $d, ?array $existing) {
        if (!$existing) $d['created_by'] = user()['id'];
        return $d;
    },
    'view' => function (array $art) {
        ?>
        <div class="grid grid-3">
          <div class="card span-2"><div class="card-body"><div class="md" data-md><?= e($art['body']) ?></div></div></div>
          <div class="card card-body small"><dl class="detail-grid" style="grid-template-columns:1fr">
            <div><dt>Status</dt><dd><?= $art['published'] ? '<span class="pill pill-success">Published</span>' : '<span class="pill pill-neutral">Draft</span>' ?></dd></div>
            <div><dt>Category</dt><dd><?= e($art['category']) ?></dd></div><div><dt>Visible to</dt><dd><?= e($art['audience']) ?></dd></div>
            <div><dt>Views</dt><dd><?= (int) $art['views'] ?></dd></div><div><dt>Feedback</dt><dd><?= (int) $art['helpful'] ?> helpful · <?= (int) $art['not_helpful'] ?> not helpful</dd></div>
            <div><dt>Preview</dt><dd><a href="<?= url('help', ['a' => 'article', 'id' => $art['id']]) ?>">Open in Help Center →</a></dd></div>
          </dl></div>
        </div>
        <script src="<?= asset('js/md.js') ?>"></script>
        <?php
    },
]);
