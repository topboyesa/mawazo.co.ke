<?php
// Follow or unfollow any member.
//   GET  follow.php -> who the visitor follows: {me, following: [usernames]}
//   POST follow.php -> user=<username>, follow=1 or 0. Members only.
require __DIR__ . '/config.php';
require_once __DIR__ . '/notify.php';
start_session();

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$me = (int) ($_SESSION['user_id'] ?? 0);

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($me === 0) {
            out(['error' => 'Log in to follow.'], 401);
        }
        if (!csrf_ok()) {
            out(['error' => 'Session expired. Reload the page.'], 403);
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([(string) ($_POST['user'] ?? '')]);
        $target = (int) $stmt->fetchColumn();

        if ($target === 0) {
            out(['error' => 'No such user.'], 404);
        }
        if ($target === $me) {
            out(['error' => "You can't follow yourself."], 400);
        }

        $want = ($_POST['follow'] ?? '') === '1';
        unnotify($pdo, $target, $me, ['follow']);
        if ($want) {
            $pdo->prepare('INSERT IGNORE INTO follows (follower_id, followed_id) VALUES (?, ?)')->execute([$me, $target]);
            notify($pdo, $target, $me, 'follow');
        } else {
            $pdo->prepare('DELETE FROM follows WHERE follower_id = ? AND followed_id = ?')->execute([$me, $target]);
        }
        out(['following' => $want]);
    }

    $following = [];
    if ($me > 0) {
        $stmt = $pdo->prepare('SELECT u.username FROM follows f JOIN users u ON u.id = f.followed_id WHERE f.follower_id = ?');
        $stmt->execute([$me]);
        $following = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    out(['me' => $_SESSION['username'] ?? null, 'following' => $following]);
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    out(['error' => 'Server error.'], 500);
}
