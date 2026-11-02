<?php
/**
 * Notifications (every signed-in user; only their own).
 *   index (?show=unread)   needs-action list + full update history, paged
 *   open (id)              mark read and go to the linked page (internal links only)
 *   read (POST id) · readall (POST)          poll (JSON, for the bell: counts + new items since an id)
 */

$me = (int) user()['id'];
$a = $_GET['a'] ?? 'index';

/** Only links inside this site are followed from a notification. */
function notif_target(?string $u): string
{
    $u = (string) $u;
    return $u !== '' && str_starts_with($u, '/') && !str_starts_with($u, '//') ? $u : url('notifications');
}

if ($a === 'poll') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    session_write_close(); // a slow poll must never block the user's other requests
    $after = int_input('after');
    $items = [];
    foreach (array_reverse(notif_feed($me, 10, $after)) as $n) {
        $items[] = ['id' => (int) $n['id'], 'title' => $n['title'], 'body' => (string) $n['body'], 'icon' => $n['icon'] ?: 'ri-notification-3-line', 'tone' => $n['tone'],
            'url' => url('notifications', ['a' => 'open', 'id' => $n['id']]), 'ago' => time_ago($n['created_at'])];
    }
    $actions = action_items();
    $unread = notif_unread($me);
    $total = array_sum(array_column($actions, 'count'));
    echo json_encode(['bell' => $total + $unread, 'action' => $total, 'unread' => $unread, 'items' => $items,
        'actions' => array_map(fn($x) => ['text' => $x['text'], 'url' => $x['url'], 'icon' => $x['icon'], 'tone' => $x['tone']], $actions)]);
    exit;
}

if ($a === 'open') {
    $n = one('SELECT * FROM hrm_notifications WHERE id = ? AND login_id = ?', [int_input('id'), $me]) ?? not_found();
    if (!$n['read_at']) q('UPDATE hrm_notifications SET read_at = NOW() WHERE id = ?', [$n['id']]);
    redirect(notif_target($n['url']));
}

if (($a === 'read' || $a === 'readall' || $a === 'unread') && is_post()) {
    if ($a === 'readall') q('UPDATE hrm_notifications SET read_at = NOW() WHERE login_id = ? AND read_at IS NULL', [$me]);
    else q('UPDATE hrm_notifications SET read_at = ' . ($a === 'read' ? 'NOW()' : 'NULL') . ' WHERE id = ? AND login_id = ?', [int_input('id'), $me]);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'unread' => notif_unread($me)]);
        exit;
    }
    redirect(url('notifications', array_filter(['show' => input('show')])));
}

$show = input('show') === 'unread' ? 'unread' : 'all';
$per = 25;
$page = max(1, int_input('page', 1));
$w = 'login_id = ?' . ($show === 'unread' ? ' AND read_at IS NULL' : '');
$total = (int) val("SELECT COUNT(*) FROM hrm_notifications WHERE $w", [$me]);
$rows = all("SELECT * FROM hrm_notifications WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), [$me]);
$unread = notif_unread($me);
$actions = action_items();
page_start('Notifications', ['Notifications'], $unread ? post_button(url('notifications', ['a' => 'readall']), '<i class="ri-check-double-line me-1"></i>Mark all as read', 'btn btn-light border') : '');
?>
<div class="grid grid-3">
  <div class="span-2 card">
    <nav class="list-tabs" aria-label="Filter">
      <a class="<?= $show === 'all' ? 'active' : '' ?>" href="<?= e(url('notifications')) ?>">All updates</a>
      <a class="<?= $show === 'unread' ? 'active' : '' ?>" href="<?= e(url('notifications', ['show' => 'unread'])) ?>">Unread<?= $unread ? ' <span class="nav-badge position-static ms-1">' . $unread . '</span>' : '' ?></a>
    </nav>
    <?php foreach ($rows as $n): ?>
      <div class="list-row notif-row<?= $n['read_at'] ? '' : ' unread' ?>">
        <span class="notif-ic t-<?= e($n['tone']) ?>"><i class="<?= e($n['icon'] ?: 'ri-notification-3-line') ?>"></i></span>
        <a class="grow text-body" href="<?= e(url('notifications', ['a' => 'open', 'id' => $n['id']])) ?>"><div class="title"><?= e($n['title']) ?></div><?= $n['body'] ? '<div class="sub">' . e($n['body']) . '</div>' : '' ?>
          <div class="small text-muted"><?= fdate($n['created_at'], 'd M Y, h:i A') ?> · <?= time_ago($n['created_at']) ?></div></a>
        <?= post_button(url('notifications', ['a' => $n['read_at'] ? 'unread' : 'read']), $n['read_at'] ? 'Mark unread' : 'Mark read', 'btn btn-sm btn-link text-muted', '', ['id' => $n['id'], 'show' => $show === 'unread' ? 'unread' : '']) ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): empty_state('ri-notification-off-line', $show === 'unread' ? 'No unread updates' : 'No updates yet', 'Approvals, shared documents, new applications and other events for you appear here.'); endif; ?>
    <?= $total > $per ? pagination($total, $page, $per, array_filter(['r' => 'notifications', 'show' => $show === 'unread' ? 'unread' : null])) : '' ?>
  </div>
  <div class="card align-self-start"><div class="card-header-lite">Needs your action</div>
    <?php foreach ($actions as $it): ?><a class="list-row text-body" href="<?= e($it['url']) ?>"><span class="notif-ic t-<?= e($it['tone']) ?>"><i class="<?= e($it['icon']) ?>"></i></span><div class="grow title small"><?= e($it['text']) ?></div><i class="ri-arrow-right-s-line text-muted"></i></a><?php endforeach; ?>
    <?php if (!$actions): ?><div class="card-body small text-muted">Nothing waiting for you.</div><?php endif; ?>
  </div>
</div>
<?php
page_end();
