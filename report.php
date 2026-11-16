<?php
// A member reports a post or a comment. The admin sees it on the reports page.
require __DIR__ . '/config.php';
require_once __DIR__ . '/notify.php';
start_session();

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['error' => 'POST only.'], 405);
}
if (empty($_SESSION['user_id'])) {
    out(['error' => 'Log in to report something.'], 401);
}
if (!csrf_ok()) {
    out(['error' => 'Session expired. Reload the page.'], 403);
}

$me      = (int) $_SESSION['user_id'];
$type    = (string) ($_POST['type'] ?? '');
$id      = (int) ($_POST['id'] ?? 0);
$reason  = (string) ($_POST['reason'] ?? '');
$details = trim((string) ($_POST['details'] ?? ''));

if (!in_array($type, ['post', 'comment'], true) || $id < 1) {
    out(['error' => 'Nothing to report.'], 400);
}
if (!in_array($reason, ['spam', 'abuse', 'false', 'other'], true)) {
    out(['error' => 'Choose a reason.'], 400);
}
if (preg_match_all('/./us', $details) > 300) {
    out(['error' => 'Keep the extra details under 300 characters.'], 400);
}

try {
    $pdo = db();

    // The thing has to exist, and you can't report your own.
    if ($type === 'post') {
        $stmt = $pdo->prepare('SELECT 1 FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            out(['error' => 'That post no longer exists.'], 404);
        }
        $authorId = post_author_id($pdo, 'post-' . $id);
    } else {
        $stmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        $authorId = $stmt->fetchColumn();
        if ($authorId === false) {
            out(['error' => 'That comment no longer exists.'], 404);
        }
        $authorId = (int) $authorId;
    }
    if ($authorId === $me) {
        out(['error' => "You can't report your own " . $type . '.'], 400);
    }

    // At most 10 reports an hour per member.
    $recent = $pdo->prepare('SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $recent->execute([$me]);
    if ((int) $recent->fetchColumn() >= 10) {
        out(['error' => "You've sent a lot of reports. Please try again later."], 429);
    }

    try {
        $pdo->prepare('INSERT INTO reports (reporter_id, type, target_id, reason, details) VALUES (?, ?, ?, ?, ?)')
            ->execute([$me, $type, $id, $reason, $details === '' ? null : $details]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            out(['error' => "You've already reported this."], 409);
        }
        throw $ex;
    }

    out(['ok' => true]);
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    out(['error' => 'Something went wrong. Please try again.'], 500);
}
