<?php
// A member's public profile: user.php?u=username
// Shows their photo, followers, who they follow, and the posts they wrote.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/notify.php';
start_session();

$name = (string) ($_GET['u'] ?? '');
$me   = (int) ($_SESSION['user_id'] ?? 0);

function profile_link(string $name, int $size = 32): string
{
    $url = 'user.php?u=' . e(rawurlencode($name));
    return '<a href="' . $url . '" style="display: inline-flex; align-items: center; gap: 10px; color: inherit; text-decoration: none;">'
        . '<img src="avatar.php?u=' . e(rawurlencode($name)) . '" alt="" width="' . $size . '" height="' . $size . '" style="flex: none; display: block; border-radius: 50%; object-fit: cover;">'
        . '<strong>@' . e($name) . '</strong></a>';
}

$user = null;
try {
    if (preg_match('/^[A-Za-z0-9_]{3,30}$/', $name)) {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT id, username, created_at FROM users WHERE username = ?');
        $stmt->execute([$name]);
        $user = $stmt->fetch() ?: null;
    }
} catch (PDOException $ex) {
    error_log($ex->getMessage());
}

if (!$user) {
    http_response_code(404);
    page_head('Profile not found');
    echo '<p style="text-align: center;">There is no member with that name.</p>';
    page_foot();
    exit;
}

$uid = (int) $user['id'];

// Followers, following, and the posts this member wrote.
$followers = $pdo->prepare('SELECT u.username FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.followed_id = ? ORDER BY f.created_at DESC LIMIT 200');
$followers->execute([$uid]);
$followerNames = $followers->fetchAll(PDO::FETCH_COLUMN);

$following = $pdo->prepare('SELECT u.username FROM follows f JOIN users u ON u.id = f.followed_id WHERE f.follower_id = ? ORDER BY f.created_at DESC LIMIT 200');
$following->execute([$uid]);
$followingNames = $following->fetchAll(PDO::FETCH_COLUMN);

$followerCount  = (int) $pdo->query('SELECT COUNT(*) FROM follows WHERE followed_id = ' . $uid)->fetchColumn();
$followingCount = (int) $pdo->query('SELECT COUNT(*) FROM follows WHERE follower_id = ' . $uid)->fetchColumn();

// Posts with no recorded author were written by the site owner (the first admin).
$ownerId = (int) $pdo->query('SELECT user_id FROM admins ORDER BY user_id ASC LIMIT 1')->fetchColumn();
$posts = $pdo->prepare(
    'SELECT p.id, p.title, p.body, p.created_at
     FROM posts p
     LEFT JOIN post_authors pa ON pa.post_id = p.id
     WHERE pa.user_id = ? OR (pa.user_id IS NULL AND ? = ?)
     ORDER BY p.id DESC
     LIMIT 100'
);
$posts->execute([$uid, $ownerId, $uid]);
$posts = $posts->fetchAll();

$isMe = $me === $uid;
$iFollow = false;
if ($me > 0 && !$isMe) {
    $stmt = $pdo->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?');
    $stmt->execute([$me, $uid]);
    $iFollow = (bool) $stmt->fetchColumn();
}

function name_list(array $names): string
{
    if (!$names) {
        return '<p style="margin: 8px 0; opacity: 0.7;">Nobody yet.</p>';
    }
    $html = '<ul style="list-style: none; margin: 8px 0; padding: 0;">';
    foreach ($names as $n) {
        $html .= '<li style="padding: 6px 0;">' . profile_link($n, 28) . '</li>';
    }
    return $html . '</ul>';
}

page_head('@' . $user['username']);
?>
<div style="text-align: center; margin-bottom: 1rem;">
    <img src="avatar.php?u=<?= e(rawurlencode($user['username'])) ?>" alt="" width="96" height="96" style="border-radius: 50%; object-fit: cover; display: block; margin: 0 auto 8px;">
    <div style="opacity: 0.7;">Joined <?= e(date('j M Y', strtotime($user['created_at']))) ?></div>

    <div style="margin-top: 12px;">
<?php if ($isMe): ?>
        <a href="profile.php">Change profile photo</a>
<?php elseif ($me > 0): ?>
        <button id="follow-btn" type="button" data-csrf="<?= e(csrf_token()) ?>" data-user="<?= e($user['username']) ?>" data-following="<?= $iFollow ? '1' : '0' ?>"
                style="padding: 8px 24px; border: 1px solid var(--line); border-radius: 24px; background: var(--fg); color: var(--bg); font: inherit; font-weight: bold; cursor: pointer;"><?= $iFollow ? 'Unfollow' : 'Follow' ?></button>
<?php else: ?>
        <a href="login.php" style="display: inline-block; padding: 8px 24px; border-radius: 24px; background: var(--fg); color: var(--bg); font-weight: bold; text-decoration: none;">Follow</a>
<?php endif; ?>
    </div>
</div>

<div style="max-width: 720px; margin: 0 auto 1.5rem; padding: 0 12px;">
    <details style="margin-bottom: 8px;">
        <summary style="cursor: pointer;"><strong id="follower-count"><?= $followerCount ?></strong> Followers</summary>
        <?= name_list($followerNames) ?>
    </details>
    <details>
        <summary style="cursor: pointer;"><strong><?= $followingCount ?></strong> Following</summary>
        <?= name_list($followingNames) ?>
    </details>
</div>

<h2>Posts</h2>
<?php if (!$posts): ?>
<p style="text-align: center;">No posts yet.</p>
<?php else: ?>
<ul style="max-width: 720px; margin: 0 auto 1.5rem; padding: 0 12px; list-style: none;">
<?php foreach ($posts as $p): ?>
<?php
    $excerpt = preg_match('/^.{0,160}/us', preg_replace('/\s+/', ' ', $p['body']), $m) ? $m[0] : '';
    if (strlen($p['body']) > strlen($excerpt)) {
        $excerpt = rtrim($excerpt) . '...';
    }
?>
    <li style="padding: 12px 0; border-bottom: 1px solid var(--line);">
        <a href="index.html#post-<?= (int) $p['id'] ?>"><strong><?= e($p['title']) ?></strong></a>
        <span style="opacity: 0.7; font-size: 0.85em;"> &middot; <?= e(date('j M Y', strtotime($p['created_at']))) ?></span>
        <div style="opacity: 0.85; overflow-wrap: anywhere;"><?= e($excerpt) ?></div>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<script>
    // The follow button: no page reload, the follower count changes straight away.
    (function () {
        var btn = document.getElementById("follow-btn");
        if (!btn) return;
        var following = btn.dataset.following === "1";
        var count = document.getElementById("follower-count");
        btn.addEventListener("click", function () {
            btn.disabled = true;
            fetch("follow.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ csrf: btn.dataset.csrf, user: btn.dataset.user, follow: following ? "0" : "1" })
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                .then(function (d) {
                    following = !!d.following;
                    btn.textContent = following ? "Unfollow" : "Follow";
                    count.textContent = Number(count.textContent) + (following ? 1 : -1);
                })
                .catch(function () {})
                .then(function () { btn.disabled = false; });
        });
    })();
</script>
<?php page_foot();
