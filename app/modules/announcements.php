<?php

$announcementRes = [
    'route' => 'announcements', 'table' => 'hrm_announcements', 'title' => 'Announcements', 'singular' => 'Announcement', 'icon' => 'ri-megaphone-line',
    'can_manage' => 'manage_org', 'order' => 'pinned DESC, created_at DESC',
    'fields' => [
        'title'  => ['label' => 'Title', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-9'],
        'pinned' => ['label' => 'Pin to top', 'type' => 'checkbox', 'list' => true, 'col' => 'col-md-3'],
        'body'   => ['label' => 'Message', 'type' => 'textarea', 'rows' => 6, 'required' => true, 'search' => true, 'col' => 'col-12'],
    ],
    'before_save' => function (array $d, ?array $existing) {
        if (!$existing) $d['created_by'] = user()['id'];
        return $d;
    },
    'after_save' => function (int $id, array $d, bool $new) {
        if ($new) notify(logins_with('view_announcements', true, user()['id'] ?? null), 'announcement', 'New announcement: ' . mb_strimwidth((string) $d['title'], 0, 120, '…'), mb_strimwidth((string) $d['body'], 0, 200, '…'), url('announcements'), 'ri-megaphone-line', 'success', "ann:$id");
    },
];

// Everyone reads announcements as a feed; the CRUD screens are for HR.
if (($_GET['a'] ?? 'index') === 'index') {
    $rows = all('SELECT a.*, l.username FROM hrm_announcements a LEFT JOIN it_login l ON l.id = a.created_by ORDER BY a.pinned DESC, a.created_at DESC LIMIT 50');
    page_start('Announcements', ['Announcements'], can('manage_org') ? '<a class="btn btn-primary" href="' . url('announcements', ['a' => 'create']) . '"><i class="ri-add-line me-1"></i>New announcement</a>' : '');
    if (!$rows) {
        echo '<div class="card">';
        empty_state('ri-megaphone-line', 'No announcements yet', 'Company news and notices will appear here.');
        echo '</div>';
    }
    echo '<div class="vstack gap-3" style="max-width:860px">';
    foreach ($rows as $a) { ?>
        <article class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between gap-3">
              <h5 class="fw-semibold mb-1"><?= $a['pinned'] ? '<i class="ri-pushpin-2-fill text-primary me-1"></i>' : '' ?><?= e($a['title']) ?></h5>
              <?php if (can('manage_org')): ?>
                <div class="text-nowrap"><a class="btn btn-sm btn-icon" href="<?= url('announcements', ['a' => 'edit', 'id' => $a['id']]) ?>"><i class="ri-pencil-line"></i></a>
                <?= post_button(url('announcements', ['a' => 'delete', 'id' => $a['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Delete this announcement?') ?></div>
              <?php endif; ?>
            </div>
            <div class="small text-muted mb-3">Posted <?= time_ago($a['created_at']) ?><?= $a['username'] ? ' by ' . e($a['username']) : '' ?></div>
            <div><?= nl2br(e($a['body'])) ?></div>
          </div>
        </article>
    <?php }
    echo '</div>';
    page_end();
    return;
}

crud($announcementRes);
