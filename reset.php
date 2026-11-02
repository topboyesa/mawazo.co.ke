<?php
// Where the emailed link lands: choose a new password.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
start_session();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$hash  = preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : '';

$errors = [];
$valid  = false;
$done   = false;

try {
    $pdo   = db();
    $reset = null;
    if ($hash !== '') {
        $stmt = $pdo->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND expires_at > NOW()');
        $stmt->execute([$hash]);
        $reset = $stmt->fetch() ?: null;
    }
    $valid = $reset !== null;

    if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');

        if (!csrf_ok()) {
            $errors[] = 'Your session expired. Please try again.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (strlen($password) > 72) {
            $errors[] = 'Password must be 72 characters or fewer.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $userId = (int) $reset['user_id'];
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            // The link works once: remove every reset link for this account, and clear any login lockout.
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM login_attempts WHERE email = (SELECT email FROM users WHERE id = ?)')->execute([$userId]);
            $pdo->commit();
            $done  = true;
            $valid = false;
        }
    }
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $errors[] = 'Something went wrong. Please try again.';
}

page_head('Reset password');
?>
<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($done): ?>
<p style="text-align: center;">Your password has been changed. <a href="login.php">Log in</a></p>
<?php elseif (!$valid): ?>
<p style="text-align: center;">This link is invalid or has expired. <a href="forgot.php">Get a new one</a></p>
<?php else: ?>
<form class="auth" method="post" action="reset.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <label for="password">New password</label>
    <input id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">

    <label for="confirm">Confirm new password</label>
    <input id="confirm" name="confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password">

    <button type="submit">Change password</button>
</form>
<?php endif; ?>
<?php page_foot();
