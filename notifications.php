<?php
// The notifications page: likes, dislikes, comments and follows. Opening it marks everything as read.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function ago(int $seconds): string
{
    if ($seconds < 60) {
        return 'just now';
    }
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$size, $name]) {
        if ($seconds >= $size) {
            $n = intdiv($seconds, $size);
            return $n . ' ' . $name . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

// Same round icon as on the main page: first letter, colour picked from the name.
function avatar_html(string $name, int $size = 40): string
{
    $hue = 0;
    foreach (str_split($name) as $ch) {
        $hue = ($hue * 31 + ord($ch)) % 360;
    }
    return '<span aria-hidden="true" style="display: inline-flex; align-items: center; justify-content: center; flex: none; width: ' . $size . 'px; height: ' . $size . 'px; border-radius: 50%; background: hsl(' . $hue . ', 55%, 40%); color: #fff; font-weight: bold; font-size: ' . round($size * 0.5) . 'px;">' . e(strtoupper(substr($name, 0, 1))) . '</span>';
}

$me       = (int) $_SESSION['user_id'];
$items    = [];
$failed   = false;
$sentence = [
    'like'    => 'liked your post',
    'dislike' => 'disliked your post',
    'comment' => 'commented on your post',
    'follow'  => 'started following you',
];

try {
    $pdo  = db();
    $stmt = $pdo->prepare(
        "SELECT n.id, n.type, n.post_id, n.is_read, UNIX_TIMESTAMP(n.created_at) AS ts, u.username AS actor, p.title
         FROM notifications n
         JOIN users u ON u.id = n.actor_id
         LEFT JOIN posts p ON n.post_id = CONCAT('post-', p.id)
         WHERE n.user_id = ?
         ORDER BY n.id DESC
         LIMIT 100"
    );
    $stmt->execute([$me]);
    $items = $stmt->fetchAll();

    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$me]);
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $failed = true;
}

page_head('Notifications');
?>
<?php if ($failed): ?>
<p style="text-align: center;">Could not load notifications. Please try again.</p>
<?php elseif (!$items): ?>
<p style="text-align: center;">Nothing yet. You'll see likes, dislikes, comments and new followers here.</p>
<?php else: ?>
<ul style="max-width: 720px; margin: 0 auto 1.5rem; padding: 0 12px; list-style: none;">
<?php foreach ($items as $n): ?>
    <li style="display: flex; align-items: center; gap: 12px; padding: 12px; border-bottom: 1px solid var(--line);<?= $n['is_read'] ? '' : ' background: var(--hover);' ?>">
        <?= avatar_html($n['actor']) ?>
        <div>
            <strong>@<?= e($n['actor']) ?></strong>
            <?= e($sentence[$n['type']] ?? $n['type']) ?>
<?php if ($n['post_id'] !== null && $n['title'] !== null): ?>
            <a href="index.html#<?= e($n['post_id']) ?>"><?= e($n['title']) ?></a>
<?php endif; ?>
            <div style="opacity: 0.7; font-size: 0.85em;"><?= e(ago(max(0, time() - (int) $n['ts']))) ?><?= $n['is_read'] ? '' : ' &middot; new' ?></div>
        </div>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_foot();
