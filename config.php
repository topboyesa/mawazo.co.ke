<?php
// Database settings. Keep this file out of Git: add "config.php" to your .gitignore.
//
// On your own computer (http://localhost/...) the site uses the local XAMPP database.
// On the live site it uses the hosting database. Fill in the live password below.
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost:') === 0) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'ai_slop');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    define('DB_HOST', 'sql111.infinityfree.com');
    define('DB_NAME', 'if0_43294070_ai_slop');
    define('DB_USER', 'if0_43294070');
    define('DB_PASS', '363RxJaXIZ');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    start_session();
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
