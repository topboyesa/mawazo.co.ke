<?php
// Any logged-in member can write posts. You can edit and delete your own; admins can manage every post.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$me  = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
$stmt->execute([$me]);
$isAdmin = (bool) $stmt->fetchColumn();

function can_manage(PDO $pdo, int $postId, int $me, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM post_authors WHERE post_id = ? AND user_id = ?');
    $stmt->execute([$postId, $me]);
    return (bool) $stmt->fetchColumn();
}

$errors = [];
$editId = 0;
$title  = '';
$body   = '';

// Opening write.php?id=4 loads post 4 into the form, if it is yours (or you are an admin).
if (isset($_GET['id']) && ctype_digit((string) $_GET['id']) && can_manage($pdo, (int) $_GET['id'], $me, $isAdmin)) {
    $stmt = $pdo->prepare('SELECT id, title, body FROM posts WHERE id = ?');
    $stmt->execute([(int) $_GET['id']]);
    if ($row = $stmt->fetch()) {
        $editId = (int) $row['id'];
        $title  = $row['title'];
        $body   = $row['body'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id     = (int) ($_POST['id'] ?? 0);

        if ($action === 'delete') {
            if (!can_manage($pdo, $id, $me, $isAdmin)) {
                $errors[] = 'You can only delete your own posts.';
            } else {
                // Remove the post and everything attached to it.
                $key = 'post-' . $id;
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
                foreach (['post_votes', 'post_views', 'comments', 'notifications'] as $table) {
                    $pdo->prepare("DELETE FROM $table WHERE post_id = ?")->execute([$key]);
                }
                $pdo->commit();
                header('Location: ' . (($_POST['back'] ?? '') === '1' ? 'index.html' : 'write.php?done=deleted'));
                exit;
            }
        }

        if ($action === 'save') {
            $title  = trim((string) ($_POST['title'] ?? ''));
            $body   = trim((string) ($_POST['body'] ?? ''));
            $editId = $id;

            $titleLength = preg_match_all('/./us', $title);
            $bodyLength  = preg_match_all('/./us', $body);
            if ($titleLength < 1 || $titleLength > 200) {
                $errors[] = 'The title must be 1 to 200 characters.';
            }
            if ($bodyLength < 1 || $bodyLength > 16000) {
                $errors[] = 'The post must be 1 to 16000 characters.';
            }
            if ($id > 0 && !can_manage($pdo, $id, $me, $isAdmin)) {
                $errors[] = 'You can only edit your own posts.';
            }

            // Slow down spam: one new post a minute for members (admins are not limited).
            if (!$errors && $id === 0 && !$isAdmin) {
                $recent = $pdo->prepare('SELECT COUNT(*) FROM post_authors pa JOIN posts p ON p.id = pa.post_id WHERE pa.user_id = ? AND p.created_at > (NOW() - INTERVAL 60 SECOND)');
                $recent->execute([$me]);
                if ((int) $recent->fetchColumn() > 0) {
                    $errors[] = 'Please wait a minute before posting again.';
                }
            }

            if (!$errors) {
                if ($id > 0) {
                    $pdo->prepare('UPDATE posts SET title = ?, body = ? WHERE id = ?')->execute([$title, $body, $id]);
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO posts (title, body) VALUES (?, ?)')->execute([$title, $body]);
                    $newId = (int) $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO post_authors (post_id, user_id) VALUES (?, ?)')->execute([$newId, $me]);
                    $pdo->commit();
                }
                header('Location: write.php?done=saved');
                exit;
            }
        }
    }
}

// Admins see every post here; everyone else sees their own.
$sql = 'SELECT p.id, p.title, UNIX_TIMESTAMP(p.created_at) AS ts, u.username
        FROM posts p
        LEFT JOIN post_authors pa ON pa.post_id = p.id
        LEFT JOIN users u ON u.id = pa.user_id'
     . ($isAdmin ? '' : ' WHERE pa.user_id = ?')
     . ' ORDER BY p.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($isAdmin ? [] : [$me]);
$posts = $stmt->fetchAll();
$done  = (string) ($_GET['done'] ?? '');

page_head($editId ? 'Edit post' : 'Write a post');
?>
<?php if ($done === 'saved'): ?>
<p style="text-align: center;">Post saved. <a href="index.html">See it on the site</a></p>
<?php elseif ($done === 'deleted'): ?>
<p style="text-align: center;">Post deleted.</p>
<?php endif; ?>

<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<form class="auth" method="post" action="write.php<?= $editId ? '?id=' . $editId : '' ?>" style="max-width: 720px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editId ?>">

    <label for="title">Title</label>
    <input id="title" name="title" type="text" value="<?= e($title) ?>" required maxlength="200">

    <label for="body">Post (a blank line starts a new paragraph)</label>
    <textarea id="body" name="body" rows="14" required maxlength="16000" style="display: block; width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--fg); font: inherit;"><?= e($body) ?></textarea>

    <button type="submit"><?= $editId ? 'Save changes' : 'Publish post' ?></button>
</form>
<?php if ($editId): ?>
<p style="text-align: center;"><a href="write.php">Write a new post instead</a></p>
<?php endif; ?>

<h2><?= $isAdmin ? 'All posts written here' : 'Your posts' ?></h2>
<?php if (!$posts): ?>
<p style="text-align: center;">Nothing yet.</p>
<?php else: ?>
<ul style="max-width: 720px; margin: 0 auto 1.5rem; padding: 0 12px; list-style: none;">
<?php foreach ($posts as $p): ?>
    <li style="padding: 8px 0; border-bottom: 1px solid var(--line);">
        <?= e($p['title']) ?>
        <span style="opacity: 0.7;"> &middot; <?= e(date('j M Y', (int) $p['ts'])) ?><?= $isAdmin && $p['username'] ? ' &middot; @' . e($p['username']) : '' ?></span>
        &middot; <a href="write.php?id=<?= (int) $p['id'] ?>">edit</a>
        &middot;
        <form method="post" action="write.php" style="display: inline;" onsubmit="return confirm('Delete this post and its comments, likes and views?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" style="background: none; border: none; padding: 0; color: inherit; font: inherit; text-decoration: underline; cursor: pointer;">delete</button>
        </form>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_foot();
