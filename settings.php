<?php
// Account settings: change your username, change your password, or delete your account.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/media-lib.php';
start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me  = (int) $_SESSION['user_id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE id = ?');
$stmt->execute([$me]);
$user = $stmt->fetch();
if (!$user) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
$stmt->execute([$me]);
$isAdmin = (bool) $stmt->fetchColumn();

$errors  = [];
$section = '';
$newName = '';
$ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

// Every change asks for your current password. Wrong guesses count towards the same
// 5-tries-in-15-minutes limit as logging in, so a stolen session can't be used to guess it.
function check_password(PDO $pdo, array $user, string $password, string $ip, array &$errors): bool
{
    $recent = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
    $recent->execute([$user['email']]);
    if ((int) $recent->fetchColumn() >= 5) {
        $errors[] = 'Too many failed attempts. Please try again in 15 minutes.';
        return false;
    }
    if (!password_verify($password, $user['password_hash'])) {
        $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$user['email'], $ip]);
        $errors[] = 'Your current password is wrong.';
        return false;
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$user['email']]);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action  = (string) ($_POST['action'] ?? '');
        $current = (string) ($_POST['current'] ?? '');

        try {
            if ($action === 'username') {
                $section = 'username';
                $newName = trim((string) ($_POST['username'] ?? ''));

                if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $newName)) {
                    $errors[] = 'Username must be 3-30 letters, numbers or underscores.';
                } elseif ($newName === $user['username']) {
                    $errors[] = 'That is already your username.';
                } else {
                    // Every username belongs to one account only (Sam and sam count as the same name).
                    $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
                    $taken->execute([$newName, $me]);
                    if ($taken->fetchColumn()) {
                        $errors[] = 'That username is already taken. Please choose another.';
                    }
                }

                if (!$errors && check_password($pdo, $user, $current, $ip, $errors)) {
                    $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$newName, $me]);
                    $_SESSION['username'] = $newName;
                    header('Location: settings.php?done=username');
                    exit;
                }
            } elseif ($action === 'password') {
                $section = 'password';
                $new     = (string) ($_POST['new'] ?? '');
                $confirm = (string) ($_POST['confirm'] ?? '');

                if (strlen($new) < 8) {
                    $errors[] = 'The new password must be at least 8 characters.';
                }
                if (strlen($new) > 72) {
                    $errors[] = 'The new password must be 72 characters or fewer.';
                }
                if ($new !== $confirm) {
                    $errors[] = 'The new passwords do not match.';
                }

                if (!$errors && check_password($pdo, $user, $current, $ip, $errors)) {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me]);
                    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$me]);   // old reset links stop working
                    session_regenerate_id(true);
                    header('Location: settings.php?done=password');
                    exit;
                }
            } elseif ($action === 'delete') {
                $section = 'delete';

                if ($isAdmin) {
                    $errors[] = "Admin accounts can't be deleted from here. Remove the admin rights in the database first.";
                } elseif (strcasecmp(trim((string) ($_POST['confirm_name'] ?? '')), $user['username']) !== 0) {
                    $errors[] = 'Type your username exactly to confirm.';
                } elseif (check_password($pdo, $user, $current, $ip, $errors)) {
                    // Remove this member's posts, and everything attached to them. Their comments, likes,
                    // follows, notifications and photo are removed by the database along with the account.
                    $pdo->beginTransaction();
                    $posts = $pdo->prepare('SELECT post_id FROM post_authors WHERE user_id = ?');
                    $posts->execute([$me]);
                    foreach ($posts->fetchAll(PDO::FETCH_COLUMN) as $postId) {
                        $key = 'post-' . $postId;
                        delete_post_media($pdo, (int) $postId);   // the picture files go too
                        foreach (['post_votes', 'post_views', 'comments', 'notifications'] as $table) {
                            $pdo->prepare("DELETE FROM $table WHERE post_id = ?")->execute([$key]);
                        }
                        $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
                    }
                    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$me]);
                    $pdo->commit();

                    $_SESSION = [];
                    if (ini_get('session.use_cookies')) {
                        $p = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                    }
                    session_destroy();
                    header('Location: index.html');
                    exit;
                }
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                $errors[] = 'That username is already taken. Please choose another.';
            } else {
                error_log($ex->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

$done = (string) ($_GET['done'] ?? '');
$token = csrf_token();

page_head('Account settings');
?>
<?php if ($done === 'username'): ?>
<p style="text-align: center;">Username changed to @<?= e($user['username']) ?>.</p>
<?php elseif ($done === 'password'): ?>
<p style="text-align: center;">Password changed.</p>
<?php endif; ?>

<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<h3 style="text-align: center;">Change username</h3>
<form class="auth" method="post" action="settings.php">
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <input type="hidden" name="action" value="username">

    <label for="username">New username (currently @<?= e($user['username']) ?>)</label>
    <input id="username" name="username" type="text" value="<?= $section === 'username' ? e($newName) : '' ?>" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_]+" autocomplete="off">

    <label for="current-u">Your password</label>
    <input id="current-u" name="current" type="password" required autocomplete="current-password">

    <button type="submit">Change username</button>
</form>

<h3 style="text-align: center;">Change password</h3>
<form class="auth" method="post" action="settings.php">
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <input type="hidden" name="action" value="password">

    <label for="current-p">Current password</label>
    <input id="current-p" name="current" type="password" required autocomplete="current-password">

    <label for="new">New password</label>
    <input id="new" name="new" type="password" required minlength="8" maxlength="72" autocomplete="new-password">

    <label for="confirm">Confirm new password</label>
    <input id="confirm" name="confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password">

    <button type="submit">Change password</button>
</form>

<h3 style="text-align: center;">Delete account</h3>
<form class="auth" method="post" action="settings.php" hx-boost="false" onsubmit="return confirm('Delete your account for good? This cannot be undone.');">
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <input type="hidden" name="action" value="delete">

    <p style="margin: 0 0 8px;">This permanently deletes your account, your posts, your comments, your likes and your followers. It can't be undone.</p>
<?php if ($isAdmin): ?>
    <p style="margin: 0 0 8px; opacity: 0.8;">Admin accounts can't be deleted from here.</p>
<?php else: ?>
    <label for="confirm-name">Type your username (<?= e($user['username']) ?>) to confirm</label>
    <input id="confirm-name" name="confirm_name" type="text" required autocomplete="off">

    <label for="current-d">Your password</label>
    <input id="current-d" name="current" type="password" required autocomplete="current-password">

    <button type="submit" style="background: #c0392b; border-color: #c0392b; color: #fff;">Delete my account</button>
<?php endif; ?>
</form>

<p style="text-align: center;"><a href="user.php?u=<?= e(rawurlencode($user['username'])) ?>">Back to my profile</a></p>
<?php page_foot();
