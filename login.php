<?php
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
start_session();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email    = substr(strtolower(trim((string) ($_POST['login'] ?? ''))), 0, 254);   // what they typed: an email or a username
        $password = (string) ($_POST['password'] ?? '');
        $ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        try {
            $pdo = db();

            // An email always has an @ and a username never does, so one box can take either.
            // Several accounts can share one email, so this can find more than one.
            $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = ? OR username = ?');
            $stmt->execute([$email, $email]);
            $accounts = $stmt->fetchAll();

            // Failed attempts are counted per email address (or per typed name when nothing matched),
            // so using the username and the email together doesn't give extra tries.
            $key = $accounts ? $accounts[0]['email'] : $email;

            // Slow down password guessing: 5 failures per email or 20 per IP in 15 minutes.
            $byEmail = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
            $byEmail->execute([$key]);
            $byIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
            $byIp->execute([$ip]);

            if ((int) $byEmail->fetchColumn() >= 5 || (int) $byIp->fetchColumn() >= 20) {
                $errors[] = 'Too many failed attempts. Please try again in 15 minutes.';
            } else {
                // Which of the matching accounts does this password belong to?
                $matches = [];
                foreach ($accounts as $account) {
                    if (password_verify($password, $account['password_hash'])) {
                        $matches[] = $account;
                    }
                }

                if (count($matches) === 1) {
                    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$key]);
                    session_regenerate_id(true);
                    $_SESSION['user_id']  = (int) $matches[0]['id'];
                    $_SESSION['username'] = $matches[0]['username'];
                    header('Location: index.html');
                    exit;
                }

                if (count($matches) > 1) {
                    $errors[] = 'More than one of your accounts uses that email and password. Please log in with your username instead.';
                } else {
                    $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$key, $ip]);
                    $errors[] = 'Invalid email, username or password.';
                }
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

page_head('Log in');
?>
<?php if ($errors): ?>
<ul class="errors">
<?php foreach ($errors as $err): ?>
    <li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<form class="auth" method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label for="login">Email or username</label>
    <input id="login" name="login" type="text" value="<?= e($email) ?>" required maxlength="254" autocomplete="username">

    <label for="password">Password</label>
    <input id="password" name="password" type="password" required maxlength="72" autocomplete="current-password">

    <button type="submit">Log in</button>
</form>

<p style="text-align: center;">No account yet? <a href="signup.php">Sign up</a></p>
<p style="text-align: center;"><a href="forgot.php">Forgot your password?</a></p>
<?php page_foot();
