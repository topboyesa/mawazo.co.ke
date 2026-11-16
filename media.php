<?php
// Shows a post's picture: media.php?id=12
// Pictures are kept in a closed folder and only ever leave it through here, with the right type label.
require __DIR__ . '/config.php';
require __DIR__ . '/media-lib.php';

$id = (int) ($_GET['id'] ?? 0);
$row = false;
try {
    $stmt = db()->prepare('SELECT file, mime FROM post_media WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
}

$path = $row ? media_dir() . '/' . basename($row['file']) : '';
if (!$row || !is_file($path) || !in_array($row['mime'], ['image/jpeg', 'image/gif'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');   // a picture never changes once saved
readfile($path);
