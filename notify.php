<?php
// Helpers that create and remove notifications. A problem here never stops the like, comment or follow itself.

// Who wrote post-N. Posts with no recorded author belong to the site owner (the first admin).
function post_author_id(PDO $pdo, string $post): int
{
    try {
        $stmt = $pdo->prepare('SELECT user_id FROM post_authors WHERE post_id = ?');
        $stmt->execute([(int) substr($post, 5)]);
        $author = $stmt->fetchColumn();
        if ($author !== false) {
            return (int) $author;
        }
        return (int) $pdo->query('SELECT user_id FROM admins ORDER BY user_id ASC LIMIT 1')->fetchColumn();
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        return 0;
    }
}

// Tell $to that $from did something. Nobody is notified about their own actions.
function notify(PDO $pdo, int $to, int $from, string $type, ?string $post = null): void
{
    if ($to <= 0 || $to === $from) {
        return;
    }
    try {
        $pdo->prepare('INSERT INTO notifications (user_id, actor_id, type, post_id) VALUES (?, ?, ?, ?)')
            ->execute([$to, $from, $type, $post]);
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
    }
}

// Take back earlier notifications, for example when a like is removed or someone unfollows.
function unnotify(PDO $pdo, int $to, int $from, array $types, ?string $post = null): void
{
    if ($to <= 0) {
        return;
    }
    try {
        $marks = implode(',', array_fill(0, count($types), '?'));
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND actor_id = ? AND type IN ($marks) AND post_id <=> ?")
            ->execute(array_merge([$to, $from], $types, [$post]));
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
    }
}
