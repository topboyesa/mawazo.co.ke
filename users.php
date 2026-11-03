<?php
// Admin-only: every member, who is online right now, and when each one was last seen.
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
$stmt->execute([(int) $_SESSION['user_id']]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    page_head('Not allowed');
    echo '<p style="text-align: center;">Only admins can see the members list.</p>';
    page_foot();
    exit;
}

function ago(int $seconds): string
{
    if ($seconds < 60) {
        return 'just now';
    }
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$size, $name]) {
        if ($seconds >= $size) {
            $n = intdiv($seconds, $size);
            return $n . ' ' . $name . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

// Same round icon as on the main page: first letter, colour picked from the name.
function avatar_html(string $name, int $size = 40): string
{
    return '<img src="avatar.php?u=' . e(rawurlencode($name)) . '" alt="" width="' . $size . '" height="' . $size . '" style="flex: none; display: block; border-radius: 50%; object-fit: cover;">';
}

$members = [];
$failed  = false;
try {
    $members = $pdo->query(
        'SELECT u.id, u.username, u.email, u.created_at AS joined,
                UNIX_TIMESTAMP(a.last_seen) AS seen,
                (ad.user_id IS NOT NULL) AS is_admin
         FROM users u
         LEFT JOIN user_activity a ON a.user_id = u.id
         LEFT JOIN admins ad ON ad.user_id = u.id
         ORDER BY (a.last_seen IS NULL), a.last_seen DESC, u.id DESC
         LIMIT 500'
    )->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $failed = true;
}

// "Online now" means active in the last 5 minutes.
$now    = time();
$online = 0;
foreach ($members as $m) {
    if ($m['seen'] !== null && $now - (int) $m['seen'] <= 300) {
        $online++;
    }
}

page_head('Members');
?>
<?php if ($failed): ?>
<p style="text-align: center;">Could not load the members. Make sure you ran the latest schema.sql.</p>
<?php else: ?>
<p style="text-align: center;"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?> &middot; <?= $online ?> online now</p>

<div style="max-width: 900px; margin: 0 auto 1.5rem; padding: 0 12px; overflow-x: auto;">
<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr style="text-align: left; border-bottom: 2px solid var(--line);">
            <th style="padding: 8px;">Member</th>
            <th style="padding: 8px;">Email</th>
            <th style="padding: 8px;">Joined</th>
            <th style="padding: 8px;">Status</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($members as $m): ?>
<?php $isOnline = $m['seen'] !== null && $now - (int) $m['seen'] <= 300; ?>
        <tr style="border-bottom: 1px solid var(--line);">
            <td style="padding: 8px;">
                <a href="user.php?u=<?= e(rawurlencode($m['username'])) ?>" style="display: inline-flex; align-items: center; gap: 10px; color: inherit; text-decoration: none;">
                    <?= avatar_html($m['username']) ?>
                    <strong>@<?= e($m['username']) ?></strong>
<?php if ($m['is_admin']): ?>
                    <span style="opacity: 0.7; font-size: 0.85em;">admin</span>
<?php endif; ?>
                </a>
            </td>
            <td style="padding: 8px; overflow-wrap: anywhere;"><?= e($m['email']) ?></td>
            <td style="padding: 8px; white-space: nowrap;"><?= e(date('j M Y', strtotime($m['joined']))) ?></td>
            <td style="padding: 8px; white-space: nowrap;">
<?php if ($isOnline): ?>
                <span style="color: #2e9e5b;">&#9679;</span> online now
<?php elseif ($m['seen'] !== null): ?>
                <span style="opacity: 0.7;">last seen <?= e(ago(max(0, $now - (int) $m['seen']))) ?></span>
<?php else: ?>
                <span style="opacity: 0.7;">no activity yet</span>
<?php endif; ?>
            </td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php page_foot();
