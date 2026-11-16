<?php
// Picture helpers for posts: checking and shrinking uploads, saving them, and deleting them.
// Videos are not supported, on purpose: they would eat the storage in no time.

const MEDIA_MAX_PER_POST    = 4;                  // pictures on one post
const MEDIA_MAX_PHOTO_BYTES = 8 * 1024 * 1024;    // largest photo you can upload (it is shrunk before saving)
const MEDIA_MAX_GIF_BYTES   = 3 * 1024 * 1024;    // largest GIF (saved as it is, so it keeps moving)
const MEDIA_MEMBER_QUOTA    = 50 * 1024 * 1024;   // total picture storage per member. Admins have no limit.

// The folder the pictures are saved in. It is shut off from direct visits: pictures are only ever shown through media.php.
function media_dir(): string
{
    $dir = __DIR__ . '/media';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

// PHP hands over several uploaded files in an awkward shape. This turns them into a simple list.
function normalize_files(?array $f): array
{
    $files = [];
    if (!$f || !isset($f['name']) || !is_array($f['name'])) {
        return $files;
    }
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = ['name' => (string) $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => (int) $f['size'][$i]];
    }
    return $files;
}

// Shrinks a photo to 1600 pixels on its longest side and saves it as a JPEG. Drawing it again from scratch
// also throws away anything hidden in the file, including the location a phone puts in its photos.
function make_photo(string $path, int $type): ?array
{
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($path); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($path); break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        default:             $src = false;
    }
    if (!$src) {
        return null;
    }

    // Phone photos are often saved sideways with a note saying how to turn them. Follow it.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif  = @exif_read_data($path);
        $angle = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($angle !== 0) {
            $turned = imagerotate($src, $angle, 0);
            if ($turned) {
                imagedestroy($src);
                $src = $turned;
            }
        }
    }

    $w     = imagesx($src);
    $h     = imagesy($src);
    $scale = min(1, 1600 / max($w, $h));
    $nw    = max(1, (int) round($w * $scale));
    $nh    = max(1, (int) round($h * $scale));

    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagejpeg($out, null, 82);
    $data = ob_get_clean();
    imagedestroy($src);
    imagedestroy($out);

    return $data === false || $data === '' ? null : ['data' => $data, 'w' => $nw, 'h' => $nh];
}

// Checks every uploaded file and gets it ready to save. If any file is a problem, nothing is returned,
// so a post is never half saved.
function prepare_media(array $files, int $room, array &$errors): array
{
    if (!$files) {
        return [];
    }
    if (count($files) > $room) {
        $errors[] = $room > 0
            ? 'You can add ' . $room . ' more picture' . ($room === 1 ? '' : 's') . ' to this post (' . MEDIA_MAX_PER_POST . ' at most).'
            : 'This post already has ' . MEDIA_MAX_PER_POST . ' pictures. Remove one to add another.';
        return [];
    }
    if (!function_exists('imagecreatetruecolor')) {
        $errors[] = "This server can't process pictures (the GD extension is switched off).";
        return [];
    }
    if (!is_writable(media_dir())) {
        $errors[] = "The pictures folder can't be written to. Check its permissions.";
        return [];
    }

    @ini_set('memory_limit', '256M');   // decoding a big photo needs room
    $ready = [];

    foreach ($files as $f) {
        $name = $f['name'];

        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $errors[] = $name . ' is too big.';
            continue;
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = $name . " couldn't be uploaded. Please try again.";
            continue;
        }

        $info = @getimagesize($f['tmp_name']);
        if (!$info) {
            $errors[] = $name . ' is not a picture.';
            continue;
        }

        if ($info[2] === IMAGETYPE_GIF) {
            // GIFs are kept as they are so they stay animated, which is why they have a smaller size limit.
            if ($f['size'] > MEDIA_MAX_GIF_BYTES) {
                $errors[] = $name . ' is too big. GIFs can be up to ' . (MEDIA_MAX_GIF_BYTES / 1048576) . ' MB.';
            } elseif ($info[0] > 2000 || $info[1] > 2000) {
                $errors[] = $name . ' is too large. GIFs can be up to 2000 by 2000 pixels.';
            } else {
                $data = file_get_contents($f['tmp_name']);
                if ($data === false || strncmp($data, 'GIF8', 4) !== 0) {
                    $errors[] = $name . " couldn't be read as a GIF.";
                } else {
                    $ready[] = ['data' => $data, 'ext' => 'gif', 'mime' => 'image/gif', 'w' => $info[0], 'h' => $info[1]];
                }
            }
        } elseif (in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            if ($f['size'] > MEDIA_MAX_PHOTO_BYTES) {
                $errors[] = $name . ' is too big. Photos can be up to ' . (MEDIA_MAX_PHOTO_BYTES / 1048576) . ' MB.';
            } elseif ($info[0] * $info[1] > 24000000) {
                $errors[] = $name . ' has too many pixels. Please choose a smaller one.';
            } else {
                $photo = make_photo($f['tmp_name'], $info[2]);
                if ($photo === null) {
                    $errors[] = $name . " couldn't be read. Try a different one.";
                } else {
                    $ready[] = ['data' => $photo['data'], 'ext' => 'jpg', 'mime' => 'image/jpeg', 'w' => $photo['w'], 'h' => $photo['h']];
                }
            }
        } else {
            $errors[] = $name . ': only JPG, PNG, WebP and GIF pictures can be added.';
        }
    }

    return $errors ? [] : $ready;
}

// How many pictures a post has, not counting the ones about to be removed.
function media_count(PDO $pdo, int $postId, array $removeIds = []): int
{
    $stmt = $pdo->prepare('SELECT id FROM post_media WHERE post_id = ?');
    $stmt->execute([$postId]);
    return count(array_diff(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), $removeIds));
}

// How much picture storage a member is using.
function member_media_bytes(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(m.size), 0) FROM post_media m JOIN post_authors pa ON pa.post_id = m.post_id WHERE pa.user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// Writes the prepared pictures to the media folder and records them.
function save_media(PDO $pdo, int $postId, array $items): void
{
    if (!$items) {
        return;
    }
    $dir = media_dir();
    $pos = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM post_media WHERE post_id = ' . $postId)->fetchColumn();

    foreach ($items as $it) {
        $file = bin2hex(random_bytes(16)) . '.' . $it['ext'];
        if (@file_put_contents($dir . '/' . $file, $it['data'], LOCK_EX) === false) {
            error_log('Could not write ' . $dir . '/' . $file);
            continue;
        }
        $pdo->prepare('INSERT INTO post_media (post_id, file, mime, width, height, size, position) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$postId, $file, $it['mime'], $it['w'], $it['h'], strlen($it['data']), ++$pos]);
    }
}

// Deletes some of a post's pictures, from the folder and from the table.
function remove_media(PDO $pdo, int $postId, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $pdo->prepare("SELECT id, file FROM post_media WHERE post_id = ? AND id IN ($marks)");
    $stmt->execute(array_merge([$postId], $ids));
    foreach ($stmt->fetchAll() as $row) {
        @unlink(media_dir() . '/' . basename($row['file']));
        $pdo->prepare('DELETE FROM post_media WHERE id = ?')->execute([(int) $row['id']]);
    }
}

// Deletes every picture of a post. Used when the post itself is deleted.
function delete_post_media(PDO $pdo, int $postId): void
{
    try {
        $stmt = $pdo->prepare('SELECT id FROM post_media WHERE post_id = ?');
        $stmt->execute([$postId]);
        remove_media($pdo, $postId, $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $ex) {
        error_log($ex->getMessage());   // no pictures table yet: nothing to delete
    }
}
