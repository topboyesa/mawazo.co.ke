<?php
// Settings for password-reset emails. You only need to fill these in on the live site.
// On localhost no email is sent: the reset link is shown on the page so you can test it.
const SITE_URL  = '';   // the folder your site lives in, no slash at the end. For example: 'https://yourname.infinityfreeapp.com'
const MAIL_FROM = '';   // the address the email comes from. For example: 'noreply@yourname.infinityfreeapp.com'

function is_local(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost:') === 0;
}

// Where reset links point. Live links always use SITE_URL, never the visitor's Host header,
// so nobody can trick the site into emailing a link to a different domain.
function site_base(): string
{
    if (is_local()) {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return 'http://' . $_SERVER['HTTP_HOST'] . $dir;
    }
    return rtrim(SITE_URL, '/');
}

function send_reset_mail(string $to, string $username, string $link): bool
{
    if (SITE_URL === '' || MAIL_FROM === '') {
        error_log('Password reset: fill in SITE_URL and MAIL_FROM in reset-config.php');
        return false;
    }
    $message = "Hi @$username,\r\n\r\n"
        . "Someone asked to reset the password for your AI SLOP account. Open this link within one hour to choose a new one:\r\n\r\n"
        . "$link\r\n\r\n"
        . "If that wasn't you, ignore this email and your password stays the same.\r\n";
    $headers = 'From: ' . MAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $sent = @mail($to, 'Reset your AI SLOP password', $message, $headers);
    if (!$sent) {
        error_log('Password reset: mail() could not send to ' . $to);
    }
    return $sent;
}
