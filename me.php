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
    try {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT 1 FROM admins WHERE user_id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $admin = (bool) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $unread = (int) $stmt->fetchColumn();
    } catch (PDOException $ex) {
        // A missing table just means no admin rights or no notifications yet.
    }
    echo json_encode(['user' => $_SESSION['username'], 'csrf' => csrf_token(), 'admin' => $admin, 'unread' => $unread]);
} else {
    echo json_encode(['user' => null]);
}
