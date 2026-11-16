<?php
// Admin-only: the reports members have sent. Dismiss one, or remove the post or comment it is about.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/media-lib.php';
start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me  = (int) $_SESSION['user_id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
$stmt->execute([$me]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    page_head('Not allowed');
    echo '<p style="text-align: center;">Only admins can see the reports.</p>';
    page_foot();
    exit;
}

$reasons = ['spam' => 'Spam', 'abuse' => 'Abusive or hateful', 'false' => 'Fake or misleading', 'other' => 'Something else'];

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

function tag(string $name): string
{
    return '<a href="user.php?u=' . e(rawurlencode($name)) . '"><strong>@' . e($name) . '</strong></a>';
}

// Removes a post and everything attached to it (pictures, comments, likes, views, notifications).
function delete_post_fully(PDO $pdo, int $id): void
{
    delete_post_media($pdo, $id);
    $key = 'post-' . $id;
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
    foreach (['post_votes', 'post_views', 'comments', 'notifications'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE post_id = ?")->execute([$key]);
    }
    $pdo->commit();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $type   = (string) ($_POST['type'] ?? '');
        $target = (int) ($_POST['target'] ?? 0);

        try {
            if ($action === 'dismiss') {
                $pdo->prepare("UPDATE reports SET status = 'dismissed', handled_by = ?, handled_at = NOW() WHERE id = ? AND status = 'open'")
                    ->execute([$me, (int) ($_POST['id'] ?? 0)]);
                header('Location: reports.php?done=dismissed');
                exit;
            }
            if ($action === 'remove' && in_array($type, ['post', 'comment'], true) && $target > 0) {
                if ($type === 'post') {
                    delete_post_fully($pdo, $target);
                } else {
                    $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$target]);
                }
                $pdo->prepare("UPDATE reports SET status = 'removed', handled_by = ?, handled_at = NOW() WHERE type = ? AND target_id = ? AND status = 'open'")
                    ->execute([$me, $type, $target]);
                header('Location: reports.php?done=removed');
                exit;
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$open = $closed = [];
$failed = false;
try {
    $open = $pdo->query(
        "SELECT r.id, r.type, r.target_id, r.reason, r.details, UNIX_TIMESTAMP(r.created_at) AS ts, u.username AS reporter
         FROM reports r JOIN users u ON u.id = r.reporter_id
         WHERE r.status = 'open' ORDER BY r.id DESC LIMIT 200"
    )->fetchAll();
    $closed = $pdo->query(
        "SELECT r.type, r.target_id, r.reason, r.status, UNIX_TIMESTAMP(r.handled_at) AS ts, u.username AS reporter
         FROM reports r JOIN users u ON u.id = r.reporter_id
         WHERE r.status <> 'open' ORDER BY r.handled_at DESC LIMIT 30"
    )->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $failed = true;
}

// What a report is about: the content itself, and who wrote it. Null if it has been deleted already.
function reported_content(PDO $pdo, array $r): ?array
{
    if ($r['type'] === 'post') {
        $stmt = $pdo->prepare('SELECT title, body FROM posts WHERE id = ?');
        $stmt->execute([(int) $r['target_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $authorId = post_author_id($pdo, 'post-' . (int) $r['target_id']);
        $who = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $who->execute([$authorId]);
        return ['title' => $row['title'], 'text' => $row['body'], 'author' => (string) $who->fetchColumn(), 'link' => 'index.html#post-' . (int) $r['target_id']];
    }
    $stmt = $pdo->prepare('SELECT c.body, c.post_id, u.username FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?');
    $stmt->execute([(int) $r['target_id']]);
    $row = $stmt->fetch();
    return $row ? ['title' => null, 'text' => $row['body'], 'author' => $row['username'], 'link' => 'index.html#' . $row['post_id']] : null;
}

$done = (string) ($_GET['done'] ?? '');
page_head('Reports');
?>
<?php if ($done === 'dismissed'): ?>
<p style="text-align: center;">Report dismissed.</p>
<?php elseif ($done === 'removed'): ?>
<p style="text-align: center;">Removed, and the reports about it are closed.</p>
<?php endif; ?>

<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($failed): ?>
<p style="text-align: center;">Could not load the reports. Make sure you ran the latest schema.sql.</p>
<?php else: ?>
<p style="text-align: center;"><?= count($open) ?> open report<?= count($open) === 1 ? '' : 's' ?></p>

<div style="max-width: 760px; margin: 0 auto 1.5rem; padding: 0 12px;">
<?php foreach ($open as $r): ?>
<?php $c = reported_content($pdo, $r); ?>
    <div style="padding: 14px; margin-bottom: 12px; border: 1px solid var(--line); border-radius: 12px;">
        <div style="opacity: 0.8; font-size: 0.9em;">
            <strong><?= e($reasons[$r['reason']] ?? $r['reason']) ?></strong> &middot; a <?= e($r['type']) ?>
            &middot; reported by <?= tag($r['reporter']) ?> &middot; <?= e(ago(max(0, time() - (int) $r['ts']))) ?>
        </div>
<?php if ($r['details'] !== null): ?>
        <div style="margin-top: 6px; font-style: italic; overflow-wrap: anywhere;">&ldquo;<?= e($r['details']) ?>&rdquo;</div>
<?php endif; ?>

<?php if ($c === null): ?>
        <p style="margin: 10px 0; opacity: 0.8;">This has already been deleted.</p>
<?php else: ?>
        <div style="margin: 10px 0; padding: 10px; background: rgba(127, 127, 127, 0.12); border-radius: 8px; overflow-wrap: anywhere;">
<?php if ($c['title'] !== null): ?>
            <div><a href="<?= e($c['link']) ?>"><strong><?= e($c['title']) ?></strong></a></div>
<?php endif; ?>
            <div style="white-space: pre-line;"><?= e(strlen($c['text']) > 400 ? substr($c['text'], 0, 400) . '...' : $c['text']) ?></div>
            <div style="margin-top: 6px; opacity: 0.8; font-size: 0.9em;">by <?= tag($c['author']) ?><?= $c['title'] === null ? ' &middot; <a href="' . e($c['link']) . '">see the post</a>' : '' ?></div>
        </div>
<?php endif; ?>

        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            <form method="post" action="reports.php" hx-boost="false" style="display: inline;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" style="padding: 6px 16px; border: 1px solid var(--line); border-radius: 20px; background: none; color: inherit; font: inherit; cursor: pointer;">Dismiss</button>
            </form>
<?php if ($c !== null): ?>
            <form method="post" action="reports.php" hx-boost="false" style="display: inline;" onsubmit="return confirm('Delete this <?= e($r['type']) ?> for good?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="type" value="<?= e($r['type']) ?>">
                <input type="hidden" name="target" value="<?= (int) $r['target_id'] ?>">
                <button type="submit" style="padding: 6px 16px; border: 1px solid #c0392b; border-radius: 20px; background: #c0392b; color: #fff; font: inherit; cursor: pointer;">Delete <?= e($r['type']) ?></button>
            </form>
<?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($closed): ?>
<h2>Handled recently</h2>
<ul style="max-width: 760px; margin: 0 auto 1.5rem; padding: 0 12px; list-style: none; opacity: 0.85;">
<?php foreach ($closed as $r): ?>
    <li style="padding: 8px 0; border-bottom: 1px solid var(--line);">
        <?= e($r['status'] === 'removed' ? 'Removed' : 'Dismissed') ?>: a <?= e($r['type']) ?> reported for <?= e(strtolower($reasons[$r['reason']] ?? $r['reason'])) ?>
        by <?= tag($r['reporter']) ?> <span style="opacity: 0.7;">&middot; <?= e(ago(max(0, time() - (int) $r['ts']))) ?></span>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>
<?php page_foot();
