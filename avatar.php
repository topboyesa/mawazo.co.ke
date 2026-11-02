<?php
// Serves a member's profile photo: avatar.php?u=username.
// Members without a photo get the round coloured letter instead, so every tag always has an icon.
require __DIR__ . '/config.php';

$name = (string) ($_GET['u'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $name)) {
    http_response_code(404);
    exit;
}

$row = false;
try {
    $stmt = db()->prepare('SELECT a.image FROM user_avatars a JOIN users u ON u.id = a.user_id WHERE u.username = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');   // the browser re-checks each time, so a new photo shows up straight away

if ($row) {
    $etag = '"' . md5($row['image']) . '"';
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: image/jpeg');
    echo $row['image'];
    exit;
}

// No photo: draw the letter circle (same colours as on the main page).
$hue = 0;
foreach (str_split($name) as $ch) {
    $hue = ($hue * 31 + ord($ch)) % 360;
}
header('Content-Type: image/svg+xml');
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="hsl(' . $hue . ', 55%, 40%)"/>'
    . '<text x="50" y="50" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="50" font-weight="bold" fill="#fff">'
    . htmlspecialchars(strtoupper($name[0]), ENT_QUOTES, 'UTF-8') . '</text></svg>';
