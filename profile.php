<?php
// Members choose or remove their profile photo here.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

@ini_set('memory_limit', '256M');   // decoding a big photo needs room

$me       = (int) $_SESSION['user_id'];
$username = (string) $_SESSION['username'];
$errors   = [];

// Turns an uploaded picture into a 256 by 256 square JPEG. Re-drawing it from scratch also
// throws away anything hidden inside the file, so only a plain picture is ever stored.
function make_avatar(string $path, int $type): ?string
{
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($path); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($path); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($path); break;
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

    $w    = imagesx($src);
    $h    = imagesy($src);
    $side = min($w, $h);
    $out  = imagecreatetruecolor(256, 256);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 256, 256, $side, $side);

    ob_start();
    imagejpeg($out, null, 85);
    $data = ob_get_clean();
    imagedestroy($src);
    imagedestroy($out);
    return $data === false || $data === '' ? null : $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            $pdo = db();

            if ($action === 'remove') {
                $pdo->prepare('DELETE FROM user_avatars WHERE user_id = ?')->execute([$me]);
                header('Location: profile.php?done=removed');
                exit;
            }

            if ($action === 'upload') {
                $file = $_FILES['photo'] ?? null;

                if (!function_exists('imagecreatetruecolor')) {
                    $errors[] = "This server can't process pictures (the GD extension is switched off).";
                } elseif (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Choose a picture first.';
                } elseif (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'That picture is too big. The limit is 5 MB.';
                } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    $errors[] = 'The upload failed. Please try again.';
                } else {
                    $info = @getimagesize($file['tmp_name']);
                    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                        $errors[] = 'Please choose a JPG, PNG, GIF or WebP picture.';
                    } elseif ($info[0] * $info[1] > 24000000) {
                        $errors[] = 'That picture has too many pixels. Please choose a smaller one.';
                    } else {
                        $data = make_avatar($file['tmp_name'], $info[2]);
                        if ($data === null) {
                            $errors[] = "That picture couldn't be read. Try a different one.";
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO user_avatars (user_id, image) VALUES (?, ?) ON DUPLICATE KEY UPDATE image = VALUES(image), updated_at = NOW()');
                            $stmt->bindValue(1, $me, PDO::PARAM_INT);
                            $stmt->bindValue(2, $data, PDO::PARAM_LOB);
                            $stmt->execute();
                            header('Location: profile.php?done=saved');
                            exit;
                        }
                    }
                }
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$hasPhoto = false;
try {
    $stmt = db()->prepare('SELECT 1 FROM user_avatars WHERE user_id = ?');
    $stmt->execute([$me]);
    $hasPhoto = (bool) $stmt->fetchColumn();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
}
$done = (string) ($_GET['done'] ?? '');

page_head('Profile photo');
?>
<?php if ($done === 'saved'): ?>
<p style="text-align: center;">Photo saved.</p>
<?php elseif ($done === 'removed'): ?>
<p style="text-align: center;">Photo removed.</p>
<?php endif; ?>

<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<div style="text-align: center; margin-bottom: 1rem;">
    <img src="avatar.php?u=<?= e(rawurlencode($username)) ?>&amp;t=<?= time() ?>" alt="" width="128" height="128" style="border-radius: 50%; object-fit: cover; display: block; margin: 0 auto 8px;">
    <strong>@<?= e($username) ?></strong>
</div>

<form class="auth" method="post" action="profile.php" enctype="multipart/form-data" hx-boost="false">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="upload">

    <label for="photo">Choose a picture (JPG, PNG, GIF or WebP, up to 5 MB)</label>
    <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required>

    <button type="submit">Upload photo</button>
</form>

<?php if ($hasPhoto): ?>
<form class="auth" method="post" action="profile.php" style="margin-top: -0.5rem;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="remove">
    <button type="submit">Remove my photo</button>
</form>
<?php endif; ?>
<?php page_foot();
