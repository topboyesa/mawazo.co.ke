<?php
// "Forgot your password?": asks for an email and sends a one-hour reset link.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/reset-config.php';
start_session();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

$errors  = [];
$sent    = false;
$devLink = null;
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } else {
            try {
                $pdo = db();
                $pdo->exec('DELETE FROM password_resets WHERE expires_at < (NOW() - INTERVAL 1 DAY)');

                $stmt = $pdo->prepare('SELECT id, username FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // At most 3 links an hour per account, so nobody can flood an inbox.
                if ($user) {
                    $recent = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
                    $recent->execute([(int) $user['id']]);
                    if ((int) $recent->fetchColumn() < 3) {
                        $token = bin2hex(random_bytes(32));
                        $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)')
                            ->execute([(int) $user['id'], hash('sha256', $token)]);

                        $link = site_base() . '/reset.php?token=' . $token;
                        if (is_local()) {
                            $devLink = $link;
                        } else {
                            send_reset_mail($email, $user['username'], $link);
                        }
                    }
                }
                // The same answer whether or not the email has an account.
                $sent = true;
            } catch (PDOException $ex) {
                error_log($ex->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

page_head('Forgot password');
?>
<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($sent): ?>
<p style="text-align: center; max-width: 480px; margin: 0 auto 1rem; padding: 0 12px;">If that email has an account, a reset link is on its way. It works for one hour.</p>
<?php if ($devLink): ?>
<p style="text-align: center; max-width: 480px; margin: 0 auto 1rem; padding: 0 12px; overflow-wrap: anywhere;">Local test: email isn't sent from localhost, so here is the link: <a href="<?= e($devLink) ?>">reset my password</a></p>
<?php endif; ?>
<?php else: ?>
<form class="auth" method="post" action="forgot.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label for="email">The email you signed up with</label>
    <input id="email" name="email" type="email" value="<?= e($email) ?>" required maxlength="254" autocomplete="email">

    <button type="submit">Send reset link</button>
</form>
<?php endif; ?>

<p style="text-align: center;"><a href="login.php">Back to log in</a></p>
<?php page_foot();
