<?php
// The edit history of one post: versions.php?post=4. Anyone can read it.
require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$id = (int) ($_GET['post'] ?? 0);

try {
    $stmt = db()->prepare(
        'SELECT v.version AS n, v.title, v.body, UNIX_TIMESTAMP(v.created_at) AS ts, u.username AS editor
         FROM post_versions v LEFT JOIN users u ON u.id = v.edited_by
         WHERE v.post_id = ?
         ORDER BY v.version ASC
         LIMIT 100'
    );
    $stmt->execute([$id]);

    $versions = [];
    foreach ($stmt->fetchAll() as $r) {
        $versions[] = [
            'n'      => (int) $r['n'],
            'title'  => $r['title'],
            'body'   => $r['body'],
            'ts'     => (int) $r['ts'],
            'editor' => $r['editor'],
        ];
    }
    echo json_encode(['versions' => $versions]);
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error.']);
}
