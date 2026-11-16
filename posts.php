<?php
// Gives the pages the posts written from the site, who wrote them,
// and what the current visitor is allowed to do with each one.
require __DIR__ . '/config.php';
start_session();

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $pdo = db();
    $me  = (int) ($_SESSION['user_id'] ?? 0);

    $admin = false;
    if ($me > 0) {
        $stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
        $stmt->execute([$me]);
        $admin = (bool) $stmt->fetchColumn();
    }

    // The site owner is the first admin. Posts with no recorded author count as theirs.
    $owner = $pdo->query('SELECT u.username FROM admins a JOIN users u ON u.id = a.user_id ORDER BY a.user_id ASC LIMIT 1')->fetchColumn();
    $owner = $owner === false ? null : $owner;

    $rows = $pdo->query(
        'SELECT p.id, p.title, p.body, p.created_at AS created, pa.user_id AS author_id, u.username AS author
         FROM posts p
         LEFT JOIN post_authors pa ON pa.post_id = p.id
         LEFT JOIN users u ON u.id = pa.user_id
         ORDER BY p.id ASC'
    )->fetchAll();

    // Pictures on each post. If the pictures table isn't there yet, posts just show without them.
    $media = [];
    try {
        foreach ($pdo->query('SELECT id, post_id, mime, width, height FROM post_media ORDER BY post_id, position, id')->fetchAll() as $m) {
            $media[(int) $m['post_id']][] = ['id' => (int) $m['id'], 'w' => (int) $m['width'], 'h' => (int) $m['height']];
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
    }

    // How many saved versions each post has. More than one means it was edited.
    $versionCounts = [];
    try {
        foreach ($pdo->query('SELECT post_id, COUNT(*) AS n FROM post_versions GROUP BY post_id')->fetchAll() as $v) {
            $versionCounts[(int) $v['post_id']] = (int) $v['n'];
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
    }

    $posts = [];
    foreach ($rows as $r) {
        $ts   = strtotime($r['created']);
        $mine = $me > 0 && $r['author_id'] !== null && (int) $r['author_id'] === $me;
        $posts[] = [
            'id'        => 'post-' . $r['id'],
            'title'     => $r['title'],
            'body'      => $r['body'],
            'author'    => $r['author'] ?? $owner,
            'canManage' => $admin || $mine,
            'media'     => $media[(int) $r['id']] ?? [],
            'versions'  => $versionCounts[(int) $r['id']] ?? 0,
            'datetime'  => date('Y-m-d\TH:i', $ts),
            'label'     => date('l jS F, Y. Hi', $ts) . ' HRS',
        ];
    }

    echo json_encode(['posts' => $posts, 'admin' => $admin, 'loggedIn' => $me > 0, 'owner' => $owner]);
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error.']);
}
