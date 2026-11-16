<?php
// Tells the main page who is logged in: the username, a logout button, the write link,
// and how many unread notifications there are for the bell.
require __DIR__ . '/config.php';
start_session();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!empty($_SESSION['user_id'])) {
    $admin  = false;
    $unread = 0;
    $openReports = 0;
    try {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $admin = (bool) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $unread = (int) $stmt->fetchColumn();

        // For admins: how many reports are waiting.
        if ($admin) {
            try {
                $openReports = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
            } catch (PDOException $ex) {
                // No reports table yet.
            }
        }

        // Remember that this member is active right now (for the admin users page).
        $pdo->prepare('INSERT INTO user_activity (user_id, last_seen) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_seen = NOW()')
            ->execute([(int) $_SESSION['user_id']]);
    } catch (PDOException $ex) {
        // A missing table just means no admin rights or no notifications yet.
    }
    echo json_encode(['user' => $_SESSION['username'], 'csrf' => csrf_token(), 'admin' => $admin, 'unread' => $unread, 'reports' => $openReports]);
} else {
    echo json_encode(['user' => null]);
}
